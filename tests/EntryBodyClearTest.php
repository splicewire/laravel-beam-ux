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

/** ADR-0225 d3/d8: Clear is an audited draft on the existing, restorable history. */
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

    public function test_clear_is_a_restorable_draft_on_the_same_history_with_audit_only(): void
    {
        $entry = $this->savedPage(self::BODY);
        $particleId = $entry->particle_id;
        $pin = $entry->published_version;
        $mirrorPath = $this->mirrorPath($entry);
        $mirror = Storage::disk('mirror')->get($mirrorPath);
        $artifact = $this->artifacts()->read($entry);

        $state = app(EntryPublication::class)->clear($entry, 'clear draft');

        $this->assertSame($particleId, $entry->fresh()->particle_id);
        $this->assertSame($pin, $state->publishedVersion);
        $this->assertTrue($state->draftPending);
        $this->assertSame([], app(EntryBodyEnvelope::class)->read($entry->fresh())->body);
        $this->assertSame($mirror, Storage::disk('mirror')->get($mirrorPath));
        $this->assertSame($artifact, $this->artifacts()->read($entry->fresh()));
        $this->assertCount(2, $state->versions);
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'beam-revision')->where('event', 'cleared')->count());
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'beam-entry')->where('event', 'cleared')->count());

        $restored = app(EntryPublication::class)->restore($entry->fresh(), $pin);
        $this->assertSame(self::BODY, app(EntryBodyEnvelope::class)->read($entry->fresh())->body);
        $this->assertSame($pin, $restored->publishedVersion);
        $this->assertTrue($restored->draftPending);
        $this->assertCount(3, $restored->versions);
    }

    public function test_clear_audit_records_the_actor_without_a_restorable_activity_preimage(): void
    {
        $entry = $this->savedPage(self::BODY);
        $actor = new ClearingUser;
        $actor->setAttribute('id', '00000000-0000-4000-8000-000000000008');
        app(EntryPublication::class)->clear($entry, actor: $actor);
        $audit = DB::table('activity_log')->where('event', 'cleared')->first();
        $this->assertSame('beam-entry', $audit->log_name);
        $this->assertSame((string) $actor->getKey(), (string) $audit->causer_id);
        $this->assertSame($actor->getMorphClass(), $audit->causer_type);
        $properties = json_decode($audit->properties, true);
        $this->assertArrayNotHasKey('old', $properties);
        $this->assertArrayNotHasKey('new', $properties);
        $this->assertNotNull($properties['headVersion']);
    }

    public function test_clear_audit_stays_on_the_entry_connection_under_another_default(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.entry_realm' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('entry_realm');
        try {
            $this->createTables();
            $entry = $this->savedPage(self::BODY)->setConnection('entry_realm');
        } finally {
            DB::setDefaultConnection($default);
        }

        $actor = new ClearingUser;
        $actor->setAttribute('id', '00000000-0000-4000-8000-000000000008');
        app(EntryPublication::class)->clear($entry, actor: $actor);

        $this->assertSame(0, DB::table('activity_log')->where('event', 'cleared')->count());
        $this->assertSame(1, DB::connection('entry_realm')->table('activity_log')->where('event', 'cleared')->count());
        $this->assertSame((string) $actor->getKey(), DB::connection('entry_realm')->table('activity_log')->where('event', 'cleared')->value('causer_id'));
        $this->assertNotNull($entry->fresh()->particle_id);
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
        // The former draft is still a prior VersionStore version; the new head is cleared.
        $this->assertSame([], json_decode($last->snapshot, true)['payload']);
        $this->assertTrue(DB::table(Beam::table('versions'))->where('versionable_id', $particleId)->get()->contains(fn ($v) => str_contains($v->snapshot, 'Unpublished draft')));
    }

    public function test_clearing_an_empty_document_keeps_its_publication_and_history(): void
    {
        // The live-data case: the G2 journey "restored" pages with `save-body {body: []}`.
        $entry = $this->savedPage([]);
        $mirrorPath = $this->mirrorPath($entry);
        Storage::disk('mirror')->assertExists($mirrorPath);
        $this->assertSame('', Storage::disk('mirror')->get($mirrorPath));
        $this->assertTrue(app(CompileEntryBody::class)->holdsEmptyDocument($entry));

        app(EntryPublication::class)->clear($entry);

        $this->assertNotNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertExists($mirrorPath);
        $this->assertTrue(app(CompileEntryBody::class)->holdsEmptyDocument($entry->fresh()));
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

    public function test_clear_leaves_both_its_published_mirror_and_siblings_unchanged(): void
    {
        $sibling = $this->savedPage(self::BODY);
        $entry = $this->savedPage(self::BODY);

        app(EntryPublication::class)->clear($entry);

        Storage::disk('mirror')->assertExists($this->mirrorPath($entry));
        Storage::disk('mirror')->assertExists($this->mirrorPath($sibling));
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

    public function test_editor_save_publish_restore_each_audit_the_explicit_actor(): void
    {
        $entry = $this->savedPage(self::BODY);
        $actor = new ClearingUser;
        $actor->setAttribute('id', '00000000-0000-4000-8000-000000000008');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        config(['beam.ux.releases.review_required' => false]);
        Gate::before(fn () => true);
        $provider = \Mockery::mock(\Illuminate\Contracts\Auth\UserProvider::class);
        $provider->shouldReceive('retrieveById')->andReturn($actor);
        $guard = \Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->shouldReceive('user')->andReturn(null);
        $guard->shouldReceive('id')->andReturn(null);
        \Illuminate\Support\Facades\Auth::shouldReceive('createUserProvider')->andReturn($provider);
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')->andReturn($guard);
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $draft = \Splicewire\Beam\Ux\Particle\EntryDraftSaveOp::handle($entry, Request::create('/x', 'POST', ['body' => self::DRAFT, 'baseVersion' => $base]), $actor);
        EntryPublishOp::handle($entry->fresh(), Request::create('/x', 'POST', ['ref' => $draft->headVersion]), $actor);
        \Splicewire\Beam\Ux\Particle\EntryVersionRestoreOp::handle($entry->fresh(), Request::create('/x', 'POST', ['ref' => $base]), $actor);
        foreach (['drafted', 'published', 'restored'] as $event) {
            $events = DB::table('activity_log')->where('log_name', 'beam-entry')->where('event', $event)->get();
            $this->assertCount(1, $events, $event);
            $this->assertSame((string) $actor->getKey(), (string) $events[0]->causer_id);
            $this->assertSame($actor->getMorphClass(), $events[0]->causer_type);
        }
    }

    public function test_named_publish_moves_a_hidden_entry_through_its_workflow(): void
    {
        app(WorkflowBindingRegistry::class)->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
        $entry = $this->savedPage(self::BODY);
        $state = app(EntryPublication::class)->recordDraft($entry, self::DRAFT);
        app(EntryPublication::class)->publishVersion($entry->fresh(), $state->headVersion);
        $this->assertSame(BeamUxEntry::MARKING_PUBLISHED, $entry->fresh()->workflow_marking);
        $this->assertSame($state->headVersion, $entry->fresh()->published_version);
    }

    public function test_named_publish_cannot_publish_an_archived_entry_or_move_its_pin(): void
    {
        app(WorkflowBindingRegistry::class)->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
        $entry = $this->savedPage(self::BODY);
        $pin = $entry->published_version;
        $this->assertTrue(app(LifecycleService::class)->transition($entry, 'archive')->applied);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::DRAFT);
        try {
            app(EntryPublication::class)->publishVersion($entry->fresh(), $state->headVersion);
            $this->fail('Archived publication was accepted');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('publish', $exception->errors());
        }
        $this->assertSame('archived', $entry->fresh()->workflow_marking);
        $this->assertSame($pin, $entry->fresh()->published_version);
    }

    public function test_a_workflow_managed_published_page_clears_its_body_and_keeps_its_marking(): void
    {
        $this->app->make(WorkflowBindingRegistry::class)->bind(UxType::Page->value, EntryPublishLifecycle::DEFINITION);
        $lifecycle = $this->app->make(LifecycleService::class);

        $entry = $this->savedPage(self::BODY);
        $this->assertTrue($lifecycle->manages($entry));
        $this->assertTrue($lifecycle->transition($entry, 'publish')->applied);

        app(EntryPublication::class)->clear($entry->fresh());

        // The working body is cleared; public visibility is the workflow's own door (unpublish/archive), not a side effect.
        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->particle_id);
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
        $this->assertCount(2, $response->json('data.versions'));
        $this->assertTrue($response->json('data.draftPending'));
        $this->assertNotNull($entry->fresh()->particle_id);
        Storage::disk('mirror')->assertExists($this->mirrorPath($entry));
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
