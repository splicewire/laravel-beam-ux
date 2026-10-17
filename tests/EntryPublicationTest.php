<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Data\EntryPublicationData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryDraftSaveOp;
use Splicewire\Beam\Ux\Particle\EntryPublishOp;
use Splicewire\Beam\Ux\Particle\EntryVersionRestoreOp;
use Splicewire\Beam\Ux\Particle\EntryVersionsShowOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **Draft, publish, and restore as two pins into one version history** (G2-BEAM-DRAFT-PUBLISH).
 *
 * The obligation this file exists for is the one a reader can see: a draft must be recorded and must
 * change NOTHING a guest is served, and a publish must move what a guest is served through the real
 * compile path. Both of those are statements about the ARTIFACT ADDRESS, not about a column, so every
 * case below reads the artifact the public renderer would address
 * ({@see EntryArtifactStore::path()} / {@see EntryArtifactStore::read()}) rather than only asserting
 * that a pin moved. A pin that moved while the reader kept getting the old module would pass a
 * column-only assertion exactly as well as a correct implementation.
 */
class EntryPublicationTest extends TestCase
{
    /** A JsonDoc body with one heading — the canvas shape a tsx entry stores. */
    private const BODY_ONE = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'One']],
    ]];

    private const BODY_TWO = [[
        'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
        'props' => [], 'children' => [['kind' => 'text', 'value' => 'Two']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);

        // A compiler with no toolchain: these cases are about WHICH body reaches the artifact and at
        // WHICH address, and a real Node run would make every one of them depend on npm.
        $this->app->singleton(EntryBodyCompiler::class, fn () => new PublicationFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    public function test_editor_draft_refuses_a_stale_base_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $base = $publication->state($entry)->headVersion;
        $first = EntryDraftSaveOp::handle($entry, Request::create('/', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => $base]), null);
        $this->assertNotSame($base, $first->headVersion);
        try {
            EntryDraftSaveOp::handle($entry->fresh(), Request::create('/', 'POST', ['body' => self::BODY_ONE, 'baseVersion' => $base]), null);
            $this->fail('stale editor save was accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $e) {
            $this->assertStringContainsString('base', $e->getMessage());
        }
        $this->assertSame($first->headVersion, $publication->state($entry->fresh())->headVersion);
        $this->assertCount(2, $publication->state($entry->fresh())->versions);
        $this->assertSame(self::BODY_TWO, $entry->fresh()->particle->payload);
    }

    public function test_named_publish_pins_the_seen_version_without_replacing_a_newer_working_head(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $seen = $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $later = $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/', 'POST', ['ref' => $seen->headVersion]), null);
        $this->assertSame($seen->headVersion, $published->publishedVersion);
        $this->assertSame($later->headVersion, $published->headVersion);
        $this->assertTrue($published->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->payload);
    }

    public function test_a_draft_is_recorded_as_a_version_and_the_reader_keeps_the_published_body(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publishedPath = $this->artifacts()->path($entry->fresh());

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'my draft');

        $this->assertTrue($state->draftPending, 'the two pins agree after a draft');
        $this->assertNotSame($state->headVersion, $state->publishedVersion);
        $this->assertCount(2, $state->versions);
        $this->assertSame('my draft', $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isHead && $state->versions[0]->isPublished);

        // The reader's whole answer: same address, same module, and it is the PREVIOUS body.
        $fresh = $entry->fresh();
        $this->assertSame($publishedPath, $this->artifacts()->path($fresh), 'a draft moved the public artifact address');
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($fresh));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_publishing_moves_the_pin_and_compiles_the_drafted_body_at_its_new_address(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);

        $state = $publication->publish($entry->fresh());

        $this->assertFalse($state->draftPending);
        $this->assertSame($state->headVersion, $state->publishedVersion, 'publish pins the working HEAD');
        $this->assertNull($state->compileError);
        // A publish straight after a draft pins THAT draft rather than minting a twin of it.
        $this->assertCount(2, $state->versions);

        $fresh = $entry->fresh();
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($fresh));
    }

    public function test_the_first_draft_baselines_the_already_live_body_so_it_stays_restorable(): void
    {
        // The case every entry authored before this path is in: a body, an artifact, and no history.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $this->assertSame(0, $this->versionRows($entry));

        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $this->assertCount(2, $state->versions, 'the live body was not recorded before the draft overwrote it');
        $baseline = $state->versions[1];
        $this->assertSame('baseline', $baseline->label);
        $this->assertTrue($baseline->isPublished, 'the baseline is what the reader is on');
        // And the reader is still reading it, at the address the pin now names.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_baseline_that_fails_after_its_snapshot_rolls_the_snapshot_back_and_a_retry_pins(): void
    {
        // review-r1 R2 (seeded pages, 2026-10-16): baseline() snapshotted (committing a version and HEAD), THEN saved the
        // pin. A failure in between left a history-bearing, pin-less row, which every later baseline() treats as an
        // unpublished draft and skips, forever. The failure here lands on the pin save, AFTER the snapshot.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry->fresh());
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, $this->versionRows($entry), 'the snapshot outlived the failed pin: the row now reads as a draft');
        $this->assertNull($entry->fresh()->published_version);

        // The retry is not a no-op: the row still has no history, so baseline() pins it.
        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the row');
        $this->assertSame(1, $this->versionRows($entry));
    }

    public function test_a_central_entry_under_a_tenant_default_rolls_its_snapshot_back_with_a_failed_pin_and_a_retry_pins(): void
    {
        // review-r1 R3 (2026-10-16): the supported central-entry shape, where EntryPromoter keeps the entry and its
        // particle on `central` while the default connection is a tenant's. baseline()'s transaction is on the entry's
        // connection; the version store wrote the version on the DEFAULT one, outside it, so a failed pin kept the
        // version and the row read as a draft forever. Needs rushing/laravel-versioning with record-connection versions.
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('central');

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Built the way EntryPromoter builds one: the particle written with its own central connection, then the entry.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new BeamParticle)->setConnection('central'),
            self::BODY_ONE,
        );
        $entry = BeamUxEntry::on('central')->create(['slug' => 'central-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame('central', $entry->getConnectionName());
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('particles'))->count(), 'the body left the entry connection');

        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            app(EntryPublication::class)->baseline($entry);
            $this->fail('a failed pin save was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame(0, \DB::connection('central')->table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on central');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'the snapshot outlived the failed pin on the tenant default');
        $this->assertNull($entry->fresh()->published_version);

        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertNotNull($entry->fresh()->published_version, 'a retry could not repair the central row');
        $this->assertSame(1, \DB::connection('central')->table(Beam::table('versions'))->count());
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count());
    }

    public function test_restoring_rolls_forward_into_a_draft_and_the_reader_keeps_the_published_body(): void
    {
        // ADR-0225 decision 5: Restore applies the frozen snapshot to the working copy and mints a new version, and does
        // NOT publish. Rolling back the live page takes Restore and then Publish (review-r1, launch L3, 2026-10-16).
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $publication->recordDraft($entry->fresh(), self::BODY_TWO);
        $published = $publication->publish($entry->fresh());
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $first = $published->versions[array_key_last($published->versions)];

        $state = $publication->restore($entry->fresh(), $first->readable);

        // FORWARD: the history grew, the restored version is still in it, and HEAD is the new row: a DRAFT.
        $this->assertCount(3, $state->versions);
        $this->assertSame("restore of {$first->readable}", $state->versions[0]->label);
        $this->assertTrue($state->versions[0]->isHead);
        $this->assertFalse($state->versions[0]->isPublished, 'a restore published the restored body');
        $this->assertTrue($state->draftPending);
        $this->assertSame($published->publishedVersion, $state->publishedVersion, 'a restore moved the publication pin');

        // The reader still gets the published body; the working copy is the restored one.
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertSame(self::BODY_ONE, BeamParticle::find($entry->fresh()->particle_id)->payload);

        // Publish is what moves the reader.
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
        $this->assertStringNotContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_publishing_a_restored_empty_document_retires_the_artifact_rather_than_serving_an_empty_module(): void
    {
        // beam-ux d033815: a cleared document is no body, so the reader must read UNAUTHORED (no
        // artifact at any address) rather than be handed a module that exports nothing. Restoring an empty
        // version only drafts it (ADR-0225 decision 5); PUBLISHING that draft is what retires the artifact,
        // and it must land in the same place as publishing a cleared body directly.
        $entry = $this->publishedPage([]);
        $publication = app(EntryPublication::class);
        $cleared = $publication->publish($entry->fresh());
        $empty = $cleared->versions[array_key_last($cleared->versions)];

        $publication->recordDraft($entry->fresh(), self::BODY_ONE);
        $publication->publish($entry->fresh());
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->restore($entry->fresh(), $empty->readable);

        // ADR-0225 decision 5: the restore is a draft, so the reader keeps the published body until Publish.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));

        $publication->publish($entry->fresh());

        $this->assertNull($this->artifacts()->read($entry->fresh()), 'an empty restore, published, left a module behind');
    }

    public function test_an_immediate_save_still_publishes_and_now_records_the_history_it_never_had(): void
    {
        // The contract `g2-beam-author-entry` proves: a save through this op is live when it returns.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $payload = EntryBodySaveOp::handle($entry, $this->saveRequest(self::BODY_TWO), actor: null);

        $this->assertNull($payload['compileError']);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));

        $state = app(EntryPublication::class)->state($entry->fresh());
        $this->assertFalse($state->draftPending, 'an immediate save left a draft pending');
        // Two: the body that was live before the save (baselined), and the one it wrote.
        $this->assertCount(2, $state->versions);
        $this->assertTrue($state->versions[0]->isPublished);
    }

    public function test_a_ref_that_names_no_version_of_this_entry_is_a_422_on_ref(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);

        try {
            app(EntryPublication::class)->restore($entry->fresh(), 'v99');
            $this->fail('an unknown ref restored something');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ref', $e->errors());
        }

        // And nothing moved: the reader is where they were.
        $this->assertStringContainsString('One', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_draft_refuses_a_canvas_document_on_a_format_that_cannot_carry_one(): void
    {
        // The mdx defect (G2-BEAM-AUTHOR-ENTRY, 2026-09-11) reached through the draft door would land
        // the damage at draft time and surface it at publish time, which is strictly worse.
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => UxType::Page->value, 'format' => 'mdx']);

        try {
            app(EntryPublication::class)->recordDraft($entry, self::BODY_ONE);
            $this->fail('a canvas document was drafted onto an mdx entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
            $this->assertStringContainsString('mdx', $e->errors()['body'][0]);
        }

        $this->assertNull($entry->fresh()->particle_id, 'the refused draft still bound a particle');
    }

    public function test_a_failed_compile_leaves_the_publish_landed_and_reports_itself(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        PublicationFakeCompiler::$fails = true;

        try {
            $state = app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            PublicationFakeCompiler::$fails = false;
        }

        $this->assertNotNull($state->compileError);
        $this->assertFalse($state->draftPending, 'a failed compile rolled the publication back');
    }

    public function test_the_artifact_address_follows_the_publication_pin_and_not_the_working_head(): void
    {
        // The mechanism the reader-visible cases above all rest on, asserted directly: the address is
        // the PUBLISHED version, so a moving HEAD cannot move it.
        $entry = $this->publishedPage(self::BODY_ONE);
        $pinned = $this->artifacts()->path($entry->fresh());

        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO);

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->getAttribute('published_version'));
        $this->assertNotSame(
            (string) $fresh->getAttribute('published_version'),
            (string) $fresh->particle->getAttribute('head_version'),
            'HEAD did not move away from the pin, so this case proves nothing',
        );
        $this->assertSame($pinned, $this->artifacts()->path($fresh));
    }

    public function test_an_entry_with_no_pin_addresses_exactly_as_it_did_before_the_pin_existed(): void
    {
        // The degrade path: a host that has not migrated the column, or an entry never taken through
        // this door, must key on the particle write as it always did.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);

        $this->assertNull($entry->fresh()->getAttribute('published_version'));

        $expected = substr(hash('xxh128', '3:'.$entry->fresh()->particle->getAttribute('updated_at')->format('U.u')), 0, 16);
        $this->assertSame($expected, $this->artifacts()->version($entry->fresh()));
    }

    public function test_every_operation_declares_its_shape_slots_and_the_one_entitlement_plane(): void
    {
        $expected = [
            EntryDraftSaveOp::class => ['save-draft', OperationKind::Write],
            EntryPublishOp::class => ['publish', OperationKind::Write],
            EntryVersionsShowOp::class => ['versions', OperationKind::Read],
            EntryVersionRestoreOp::class => ['restore', OperationKind::Write],
        ];

        foreach ($expected as $class => [$name, $kind]) {
            $op = (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();

            $this->assertSame('beam-ux-entry', $op->resource, $class);
            $this->assertSame($name, $op->name, $class);
            $this->assertSame($kind, $op->kind, $class);
            // One plane for the whole family, subject-free (particle-operation-surface ticket 08): an
            // author who can publish but not list, or draft but not publish, has half an editor.
            $this->assertSame('ux.author', $op->ability, $class);
            $this->assertFalse($op->abilityModel, $class);
            $this->assertSame(EntryPublicationData::class, $op->output, $class);
        }
    }

    public function test_the_versions_read_projects_the_same_state_the_writes_return(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        app(EntryPublication::class)->recordDraft($entry->fresh(), self::BODY_TWO, 'wip');

        $state = EntryVersionsShowOp::handle($entry->fresh(), new Request, actor: null);

        $this->assertInstanceOf(EntryPublicationData::class, $state);
        $this->assertTrue($state->draftPending);
        $this->assertSame('wip', $state->versions[0]->label);
        $this->assertSame((string) $entry->getKey(), $state->id);
    }

    public function test_the_publish_and_draft_operations_round_trip_through_their_declared_inputs(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);

        $drafted = EntryDraftSaveOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['body' => self::BODY_TWO, 'baseVersion' => app(EntryPublication::class)->state($entry->fresh())->headVersion, 'label' => 'from the op']),
            actor: null,
        );
        $this->assertInstanceOf(EntryPublicationData::class, $drafted);
        $this->assertTrue($drafted->draftPending);
        $this->assertSame('from the op', $drafted->versions[0]->label);

        $published = EntryPublishOp::handle($entry->fresh(), Request::create('/x', 'POST', ['ref' => $drafted->headVersion]), actor: null);
        $this->assertFalse($published->draftPending);

        $restored = EntryVersionRestoreOp::handle(
            $entry->fresh(),
            Request::create('/x', 'POST', ['ref' => $published->versions[array_key_last($published->versions)]->readable]),
            actor: null,
        );
        // ADR-0225 decision 5: a draft; the reader keeps the published body (Two).
        $this->assertTrue($restored->draftPending);
        $this->assertStringContainsString('Two', (string) $this->artifacts()->read($entry->fresh()));
    }

    public function test_a_host_without_the_version_store_refuses_to_publish_and_names_the_fix(): void
    {
        // ADR-0225 decision 2: the pin is required wherever the editor is mounted. The pin-less branch
        // that used to mirror and compile at the unpinned address is gone; a save-body or publish on a
        // host missing the store refuses, naming both missing halves and the command that fixes it.
        $entry = $this->pageWithUnversionedBody(self::BODY_ONE);
        $artifactBefore = $this->artifacts()->read($entry->fresh());
        Schema::drop(Beam::table('versions'));

        try {
            EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest(self::BODY_TWO), actor: null);
            $this->fail('save-body published pin-less on a host without the version store');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/beam-ux-migrations/', $e->getMessage());
            $this->assertMatchesRegularExpression('/beam_versions|versions\] table/', $e->getMessage());
        }
        // Refused BEFORE the write: the working copy is still the body that was live.
        $this->assertSame(self::BODY_ONE, $entry->fresh()->particle->getAttribute('payload'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor:publish/');
        try {
            app(EntryPublication::class)->publish($entry->fresh());
        } finally {
            // Nothing moved: the reader keeps exactly the artifact it had.
            $this->assertSame($artifactBefore, $this->artifacts()->read($entry->fresh()));
            $this->assertNull($entry->fresh()->getAttribute('published_version'));
        }
    }

    private function artifacts(): EntryArtifactStore
    {
        return app(EntryArtifactStore::class);
    }

    /** An entry with a body and an artifact, already taken through the publish path once. */
    private function publishedPage(array $body): BeamUxEntry
    {
        $entry = $this->pageWithUnversionedBody($body);

        app(EntryPublication::class)->publish($entry->fresh());

        return $entry->fresh();
    }

    private function bindSplitTenancy(string $central = 'central_db'): void
    {
        // The entry stays on the default (testing) connection, which is not this central connection, so
        // it is tenant-resident while its DB ops still work.
        $this->app->instance(TenancyContext::class, new class($central) implements TenancyContext
        {
            public function __construct(private string $central) {}

            public function isMultiTenant(): bool { return true; }

            public function isActive(): bool { return true; }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return 1; }

            public function centralConnection(): ?string { return $this->central; }
        });
    }

    /**
     * review-r1, EP-07a boundary-fix re-review (2026-10-17): Publish guards the working copy, which it must read
     * on the ENTRY's connection. Read on the default one, a tenant entry on its own connection showed the guard
     * nothing (`[]`), passed, and the real body was pinned and compiled unchecked.
     */
    public function test_publish_guards_the_working_copy_on_the_entrys_own_connection(): void
    {
        config(['database.connections.tenant_acme' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->createTables('tenant_acme');
        $this->bindSplitTenancy();

        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        // Code written straight to the particle, as history from before the save check, on the tenant's connection.
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(
            (new \Splicewire\Beam\Models\BeamParticle)->setConnection('tenant_acme'),
            [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']],
        );
        $entry = BeamUxEntry::on('tenant_acme')->create(['slug' => 'tenant-home', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site', 'particle_id' => $particle->id]);
        $this->assertSame(0, \DB::table(Beam::table('particles'))->count(), 'the default connection must hold no body, or the probe proves nothing');

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('publish pinned a tenant body it never checked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version);
        $this->assertSame(0, \DB::connection('tenant_acme')->table(Beam::table('versions'))->count(), 'a refused publish recorded a version');
    }

    /**
     * review-r1 R2 (2026-10-17): publish() baselined first, and on an entry with no history baseline() pins,
     * mirrors and compiles the working copy itself, so code was published before the guard refused it. The
     * guard now runs before any write: a refused first publish leaves history, pin, artifact and mirror as they were.
     */
    public function test_a_refused_first_publish_writes_nothing(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        // History-less, as a body written before the save check existed; then the entry is tenant-resident.
        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new \Splicewire\Beam\Models\BeamParticle, [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('a code-bearing first publish was not refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('opaque', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    /** The legality half of the same order: an illegal history-less body is checked against nothing published, not itself. */
    public function test_a_first_publish_of_an_illegal_body_is_refused(): void
    {
        Storage::fake('mirror');
        config(['beam.ux.storage.mirror_disk' => 'mirror']);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        $entry = BeamUxEntry::create(['slug' => 'legacy', 'type' => UxType::Page->value, 'format' => 'tsx', 'realm' => 'site']);
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        $particle = app(\Splicewire\Beam\Write\ParticleWriter::class)->write(new \Splicewire\Beam\Models\BeamParticle, [['kind' => 'block', 'id' => 'mq', 'name' => 'marquee', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]]);
        $entry->forceFill(['particle_id' => $particle->id])->save();
        $this->bindSplitTenancy();
        $artifactsBefore = Storage::disk('artifacts')->allFiles();

        try {
            app(EntryPublication::class)->publish($entry->fresh());
            $this->fail('an illegal first publish passed because it was compared with its own baseline');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('marquee', implode("\n", $e->errors()['body']));
        }

        $this->assertNull($entry->fresh()->published_version, 'the pin moved');
        $this->assertSame(0, \DB::table(Beam::table('versions'))->count(), 'history was written');
        $this->assertSame($artifactsBefore, Storage::disk('artifacts')->allFiles(), 'an artifact was compiled');
        $this->assertSame([], Storage::disk('mirror')->allFiles(), 'the body was mirrored');
    }

    public function test_a_tenant_save_draft_with_an_opaque_node_is_refused_at_the_choke(): void
    {
        // HIGH-1: save-draft -> recordDraft -> write(), the choke. A tenant entry refuses an opaque node
        // (code) even though EntryDraftSaveOp itself calls no guard.
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{steal()}']], 'draft');
    }

    public function test_a_tenant_save_draft_with_an_expression_prop_is_refused_at_the_choke(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->recordDraft($entry->fresh(), [[
            'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false,
            'props' => [['name' => 'x', 'kind' => 'expression', 'value' => 'user.isAdmin']], 'children' => [],
        ]], 'draft');
    }

    public function test_a_restore_of_a_code_bearing_version_on_a_tenant_entry_is_refused(): void
    {
        // A code-bearing version recorded while central, then the entry is tenant-owned: restore refuses
        // it BEFORE applying (ADR-0227 d1), so a tenant cannot republish old code via restore + publish.
        $entry = $this->publishedPage(self::BODY_ONE);
        $state = app(EntryPublication::class)->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'code draft');
        $codeRef = $state->versions[0]->readable;

        $this->bindSplitTenancy();

        $this->expectException(ValidationException::class);
        app(EntryPublication::class)->restore($entry->fresh(), $codeRef);
    }

    public function test_publishing_a_historical_code_version_refuses_before_any_write(): void
    {
        $entry = $this->publishedPage(self::BODY_ONE);
        $publication = app(EntryPublication::class);
        $state = $publication->recordDraft($entry->fresh(), [['kind' => 'opaque', 'reason' => 'map', 'source' => '{code()}']], 'old code');
        $before = $entry->fresh()->getAttributes();
        $this->bindSplitTenancy();

        try {
            $publication->publishVersion($entry->fresh(), $state->headVersion);
            $this->fail('A historical code version was published without admission.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame($state->publishedVersion, $publication->state($entry->fresh())->publishedVersion);
    }

    /** An entry with a written body and NO version history — every entry authored before this path. */
    private function pageWithUnversionedBody(array $body): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => 'home-'.substr(bin2hex(random_bytes(4)), 0, 8),
            'type' => UxType::Page->value,
            'format' => 'tsx',
            'realm' => 'site',
        ]);

        app(EntryPublication::class)->write($entry, $body);

        // Compiled the way the console backfill would: at whatever address the unpinned entry has.
        app(CompileEntryBody::class)->forEntry($entry->refresh(), force: true);

        return $entry->fresh();
    }

    private function saveRequest(array $body): Request
    {
        return Request::create('/beam-ux-entries/x/save-body', 'POST', ['body' => $body]);
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))
            ->where('versionable_id', (string) $entry->fresh()->particle_id)
            ->count();
    }

    private function createTables(?string $connection = null): void
    {
        Schema::connection($connection)->create('activity_log', function (Blueprint $table) {
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

        Schema::connection($connection)->create('beam_ux_entries', function (Blueprint $table) {
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

        Schema::connection($connection)->create(Beam::table('particles'), function (Blueprint $table) {
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

        Schema::connection($connection)->create(Beam::table('versions'), function (Blueprint $table) {
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
    }
}

/** A compiler with no toolchain — it echoes the source back as a module. */
class PublicationFakeCompiler implements EntryBodyCompiler
{
    public static bool $fails = false;

    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        if (self::$fails) {
            throw CompilationFailed::for($entry, 'the fake compiler was told to fail.');
        }

        return '/* compiled */ export default () => '.json_encode($source);
    }
}
