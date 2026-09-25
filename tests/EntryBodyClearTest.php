<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Revisions\RevisionRecorder;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodyClearOp;
use Splicewire\Beam\Ux\Particle\EntryBodyEnvelope;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryPublishOp;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;

/**
 * **`clear-body` — the one act that removes a page's content** (compile-on-save-tower follow-up 1).
 *
 * Before it existed, "removing" content meant saving `[]`, which binds and publishes an empty document
 * and writes a 0-byte mirror file. Owner ruling: that empty-save behaviour is CORRECT and stays (an
 * author may save an empty body on purpose); what was missing is an operation that returns the entry to
 * "never authored". Every case below reads what a reader, the doctor and the disk would see — the
 * artifact store, the mirror disk, the body envelope — rather than only the `particle_id` column.
 */
class EntryBodyClearTest extends TestCase
{
    private const BODY = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Authored']],
    ]];

    private const DRAFT = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Unpublished draft']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        Storage::fake('mirror');
        config(['beam.ux.compile.disk' => 'artifacts', 'beam.ux.storage.mirror_disk' => 'mirror']);

        $this->app->singleton(EntryBodyCompiler::class, fn () => new ClearFakeCompiler);
        foreach ([CompileEntryBody::class, EntryArtifactStore::class, PlacedDiskMirror::class, EntryPublication::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->app->bind(AwaitingStore::class, fn () => new class implements AwaitingStore
        {
            public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void {}

            public function clearForPlaces(Model $subject, array $places): void {}

            public function clearForSubject(Model $subject): void {}
        });
    }

    public function test_clearing_removes_the_body_the_mirror_file_and_the_artifact_and_reads_as_unauthored(): void
    {
        $entry = $this->savedPage(self::BODY);
        $mirrorPath = $this->mirrorPath($entry);
        $this->assertNotNull($this->artifacts()->read($entry), 'precondition: the save compiled an artifact');
        Storage::disk('mirror')->assertExists($mirrorPath);

        $state = app(EntryPublication::class)->clear($entry);

        $fresh = $entry->fresh();
        $this->assertNull($fresh->particle_id, 'the entry is still bound to its particle');
        $this->assertNull($fresh->getAttribute('published_version'));
        Storage::disk('mirror')->assertMissing($mirrorPath);
        $this->assertSame([], Storage::disk('artifacts')->allFiles(), 'an artifact survived the clear');

        // Every reader's answer is the unauthored one, not the empty-document one.
        $this->assertFalse(app(CompileEntryBody::class)->holdsEmptyDocument($fresh));
        $this->assertNull(app(CompileEntryBody::class)->sourceFor($fresh));
        $this->assertSame([], app(EntryBodyEnvelope::class)->read($fresh)->body);
        $this->assertSame([], $state->versions);
        $this->assertNull($state->publishedVersion);
        $this->assertFalse($state->draftPending);
    }

    public function test_the_clear_is_recorded_on_the_particle_history_and_the_entry_revision_log(): void
    {
        $entry = $this->savedPage(self::BODY);
        $particleId = (string) $entry->particle_id;
        $pin = (string) $entry->getAttribute('published_version');

        app(EntryPublication::class)->clear($entry, 'retired');

        // The particle row is kept, and its history gained a final version freezing the removed body.
        $particle = BeamParticle::query()->find($particleId);
        $this->assertNotNull($particle, 'the clear deleted the particle and its history with it');
        $last = DB::table(Beam::table('versions'))->where('versionable_id', $particleId)->orderByDesc('version')->first();
        $this->assertSame('retired', $last->label);
        $this->assertStringContainsString('Authored', (string) $last->snapshot);

        // The entry side names the binding it lost, so the unbound particle is findable from the entry.
        $revision = collect(app(RevisionRecorder::class)->history($entry->fresh()))->firstWhere('cause', 'cleared');
        $this->assertNotNull($revision, 'no `cleared` revision was recorded on the entry');
        $this->assertSame(['particle_id' => $particleId, 'published_version' => $pin], $revision->old);
        $this->assertSame(['particle_id' => null, 'published_version' => null], $revision->new);

        // And the revision is a real pre-image: reverting it rebinds the entry to its history.
        app(RevisionRecorder::class)->revert($revision);
        $this->assertSame($particleId, (string) $entry->fresh()->particle_id);
        // The published body the save recorded, plus the version that froze it at the clear.
        $this->assertCount(2, app(EntryPublication::class)->state($entry->fresh())->versions);
    }

    public function test_an_unpublished_draft_is_frozen_in_history_rather_than_lost(): void
    {
        $entry = $this->savedPage(self::BODY);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::DRAFT);
        $particleId = (string) $entry->fresh()->particle_id;

        app(EntryPublication::class)->clear($entry->fresh());

        $last = DB::table(Beam::table('versions'))->where('versionable_id', $particleId)->orderByDesc('version')->first();
        $this->assertSame('cleared', $last->label);
        $this->assertStringContainsString('Unpublished draft', (string) $last->snapshot);
    }

    public function test_an_entry_holding_an_empty_document_clears_to_unauthored_and_loses_its_empty_mirror_file(): void
    {
        // The live-data case: the G2 journey "restored" pages with `save-body {body: []}`.
        $entry = $this->savedPage([]);
        $mirrorPath = $this->mirrorPath($entry);
        Storage::disk('mirror')->assertExists($mirrorPath);
        $this->assertSame('', Storage::disk('mirror')->get($mirrorPath));
        $this->assertTrue(app(CompileEntryBody::class)->holdsEmptyDocument($entry));

        app(EntryPublication::class)->clear($entry);

        $this->assertNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertMissing($mirrorPath);
        $this->assertFalse(app(CompileEntryBody::class)->holdsEmptyDocument($entry->fresh()));
    }

    public function test_saving_an_empty_body_still_writes_an_empty_mirror_file(): void
    {
        // Owner ruling: an empty body is an authored act. The clear op must not have changed it.
        $entry = $this->savedPage(self::BODY);

        EntryBodySaveOp::handle($entry->fresh(), Request::create('/x', 'POST', ['body' => []]), actor: null);

        $this->assertNotNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertExists($this->mirrorPath($entry));
        $this->assertSame('', Storage::disk('mirror')->get($this->mirrorPath($entry)));
    }

    public function test_clearing_an_unauthored_entry_is_a_no_op_that_leaves_disk_source_alone(): void
    {
        // An unbound row may sit over a disk-authored file (RegisterEntriesFromDisk registers exactly
        // those). Nothing a publish of THIS entry wrote is there to remove.
        $entry = BeamUxEntry::create(['slug' => 'from-disk', 'type' => UxType::Page->value, 'format' => 'tsx']);
        $path = $this->mirrorPath($entry);
        Storage::disk('mirror')->put($path, 'export default () => null;');

        $state = app(EntryPublication::class)->clear($entry);

        Storage::disk('mirror')->assertExists($path);
        $this->assertNull($entry->fresh()->particle_id);
        $this->assertSame([], $state->versions);
        $this->assertNull(collect(app(RevisionRecorder::class)->history($entry))->firstWhere('cause', 'cleared'));
    }

    public function test_the_op_declares_the_publish_gate_its_shape_slots_and_the_entitlement_plane(): void
    {
        $clear = $this->opAttribute(EntryBodyClearOp::class);

        $this->assertSame('beam-ux-entry', $clear->resource);
        $this->assertSame('clear-body', $clear->name);
        $this->assertSame(OperationKind::Write, $clear->kind);
        $this->assertSame(EntryPublicationData::class, $clear->output);
        $this->assertFalse($clear->abilityModel);

        // The same gate as the two doors that change what readers are served: the body publish and the
        // workflow transition. A clear on a narrower or a different gate would be the odd one out.
        foreach ([EntryPublishOp::class, EntryWorkflowTransitionOp::class, EntryBodySaveOp::class] as $publishing) {
            $this->assertSame($this->opAttribute($publishing)->ability, $clear->ability, $publishing);
            $this->assertSame($this->opAttribute($publishing)->abilityModel, $clear->abilityModel, $publishing);
        }
    }

    public function test_a_workflow_managed_published_page_clears_its_body_and_keeps_its_marking(): void
    {
        $this->app->make(WorkflowBindingRegistry::class)->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
        $lifecycle = $this->app->make(LifecycleService::class);

        $entry = $this->savedPage(self::BODY);
        $this->assertTrue($lifecycle->manages($entry));
        $this->assertTrue($lifecycle->transition($entry, 'publish')->applied);

        app(EntryPublication::class)->clear($entry->fresh());

        // The body went; visibility is the workflow's own door (unpublish/archive), not a side effect.
        $fresh = $entry->fresh();
        $this->assertNull($fresh->particle_id);
        $this->assertSame(BeamUxEntry::MARKING_PUBLISHED, $fresh->workflow_marking);
        $this->assertContains('unpublish', $lifecycle->available($fresh));
    }

    public function test_an_author_with_the_entitlement_clears_over_http(): void
    {
        Gate::define('entitlement:ux.author', fn (?User $user = null) => $user instanceof ClearingUser);
        $entry = $this->savedPage(self::BODY);
        $this->mountClearOp();

        $this->actingAs(new ClearingUser);

        $response = $this->postJson("/beam-ux-entries/{$entry->id}/clear-body", ['label' => 'over http'])->assertOk();

        $this->assertSame((string) $entry->id, $response->json('data.id'));
        $this->assertSame([], $response->json('data.versions'));
        $this->assertNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertMissing($this->mirrorPath($entry));
    }

    public function test_an_actor_without_the_entitlement_is_refused_and_nothing_is_removed(): void
    {
        Gate::define('entitlement:ux.author', fn (?User $user = null) => false);
        Gate::define('ux.author', fn (?User $user = null) => true);
        $entry = $this->savedPage(self::BODY);
        $this->mountClearOp();

        $this->actingAs(new ClearingUser);

        $this->postJson("/beam-ux-entries/{$entry->id}/clear-body")->assertForbidden();

        $this->assertNotNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertExists($this->mirrorPath($entry));
        $this->assertNotNull($this->artifacts()->read($entry->fresh()));
    }

    private function mountClearOp(): void
    {
        $this->app->make(AttributedParticleDiscovery::class)->registerClass(EntryBodyClearOp::class);

        Particle::ops('beam-ux-entries', 'beam-ux-entry', 'clear-body');
    }

    private function opAttribute(string $class): ParticleOp
    {
        return (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();
    }

    /** A page authored through the real immediate-publish op: bound, versioned, mirrored, compiled. */
    private function savedPage(array $body): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => 'page-'.substr(bin2hex(random_bytes(4)), 0, 8),
            'type' => UxType::Page->value,
            'format' => 'tsx',
            'realm' => 'site',
            'namespace' => 'starter',
        ]);

        EntryBodySaveOp::handle($entry, Request::create('/x', 'POST', ['body' => $body]), actor: null);

        return $entry->fresh();
    }

    private function mirrorPath(BeamUxEntry $entry): string
    {
        return app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
    }

    private function artifacts(): EntryArtifactStore
    {
        return app(EntryArtifactStore::class);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        $this->runBeamWorkflowsMigrations();
    }
}

class ClearingUser extends User
{
    protected $table = 'users';
}

/** A compiler with no toolchain — it echoes the source back as a module. */
class ClearFakeCompiler implements EntryBodyCompiler
{
    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        return '/* compiled */ export default () => '.json_encode($source);
    }
}
