<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\JsonDoc\TsxLens;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * An entry registered from disk is BORN PUBLISHED (ADR-0225 decision 2), like a seeded page.
 *
 * `RegisterEntriesFromDisk` (register-from-disk, ADR-0164) is the largest pin-less population on a fresh install: one
 * www `splicewire:beam:seed` inserted 33 entry rows and 2 version rows (launch row qitem-20261016215906-483ede25;
 * ticket 00 nomination 50c25971). It now registers, writes and pins in one transaction, a re-scan repairs a pin-less row
 * that has no history (never publishing a history-bearing draft), and failures surface.
 */
class DiskEntriesPublicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // The default WriteGate delegates to the Laravel gate; a host grants the console import its writes.
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new DiskFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);

        $this->root = sys_get_temp_dir().'/beamux-disk-pub-'.uniqid();
        mkdir($this->root.'/page', 0777, true);
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n# Guide\n\nRegistered from disk.\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/page/guide.mdx');
        @rmdir($this->root.'/page');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_baseline_keeps_an_imported_legacy_disk_path_without_a_second_source(): void
    {
        $disk = Storage::fake('legacy-source');
        $disk->put('access.mdx', "# Access\n");
        $scanner = app(RegisterEntriesFromDisk::class);
        $scanner->scan($disk->path(''), default: UxType::Page);
        $entry = BeamUxEntry::query()->where('slug', 'access')->firstOrFail();
        $this->assertSame('disk:access.mdx', $entry->origin);

        // Reproduce an imported pre-L3 row: body present, no history or publication pin.
        $entry->forceFill(['published_version' => null])->save();
        \DB::table(Beam::table('versions'))->where('versionable_id', $entry->particle_id)->delete();
        $this->app->instance(PlacedDiskMirror::class, new PlacedDiskMirror($disk));
        $this->app->forgetInstance(EntryPublication::class);
        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertSame(['access.mdx'], $disk->allFiles(), 'baseline created a second recognized source');
        $this->assertSame("# Access\n", $disk->get('access.mdx'));
        $this->assertNotNull($entry->fresh()->published_version);
        $plan = $scanner->plan($disk->path(''), UxType::Page);
        $this->assertCount(1, $plan['matched']);
        $this->assertSame([], $plan['unregistered']);
    }

    private function guide(): ?BeamUxEntry
    {
        return BeamUxEntry::query()->where('slug', 'guide')->whereNull('namespace')->first();
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))->where('versionable_id', (string) $entry->fresh()->particle_id)->count();
    }

    public function test_a_registered_tsx_page_is_lowered_to_a_jsondoc_through_the_lens(): void
    {
        // EP-06 d9: a disk-registered tsx page stores a JsonDoc LOWERED through the Node TSX Lens, not raw
        // source (the mechanism this method used to defer). A fake lens stands in for the Node run (proven
        // end-to-end on tots); a fresh registration has no Working Head, so the rematch mints fresh ids.
        $this->app->bind(TsxLens::class, fn () => new class implements TsxLens
        {
            public function lower(string $source): array
            {
                return [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From disk']], 'dynamic' => false]];
            }
        });
        file_put_contents($this->root.'/page/home.tsx', 'export default () => <section>From disk</section>');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = BeamUxEntry::query()->where('slug', 'home')->whereNull('namespace')->firstOrFail();
        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)->body;

        $this->assertSame('section', $body[0]['name']);
        $this->assertArrayHasKey('id', $body[0]); // a stable id minted by the rematch, not raw source

        @unlink($this->root.'/page/home.tsx');
    }

    /** A fake lens (the Node run is proven end-to-end elsewhere) and a mirror disk rooted at the scanned tree. */
    private function mirrorOverScannedTree(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $this->app->bind(TsxLens::class, fn () => new class implements TsxLens
        {
            public function lower(string $source): array
            {
                return [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From disk']], 'dynamic' => false]];
            }
        });
        $disk = Storage::fake('mirror-source');
        $this->app->instance(PlacedDiskMirror::class, new PlacedDiskMirror($disk));
        $this->app->forgetInstance(EntryPublication::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);

        return $disk;
    }

    public function test_registering_a_tsx_page_never_rewrites_the_file_it_read(): void
    {
        // Lowering is not byte-preserving (ADR-0228 d9): mirroring the registered body back rewrote the authored file into
        // canonical TSX, so a dev seed dirtied tracked sources (launch row qitem-20261020153346-104d5668).
        $disk = $this->mirrorOverScannedTree();
        $authored = "// Authored.\nexport default function Home() {\n    return <section>From disk</section>;\n}\n";
        $disk->put('page/home.tsx', $authored);
        touch($disk->path('page/home.tsx'), $before = time() - 3600);
        clearstatcache();

        app(RegisterEntriesFromDisk::class)->scan($disk->path(''));

        $entry = BeamUxEntry::query()->where('slug', 'home')->whereNull('namespace')->firstOrFail();
        $this->assertNotNull($entry->published_version, 'the entry was not born published');
        $this->assertSame('page/home.tsx', app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry),
            'the mirror target is not the file that was read, so this test proves nothing');
        clearstatcache();
        $this->assertSame($authored, $disk->get('page/home.tsx'));
        $this->assertSame($before, filemtime($disk->path('page/home.tsx')));
    }

    public function test_hydrating_an_empty_entry_from_its_file_never_rewrites_that_file(): void
    {
        $disk = $this->mirrorOverScannedTree();
        $authored = "// Authored.\nexport default function Home() {\n    return <section>From disk</section>;\n}\n";
        $disk->put('page/home.tsx', $authored);
        touch($disk->path('page/home.tsx'), $before = time() - 3600);
        clearstatcache();
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'title' => 'Home']);

        $this->assertTrue(app(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, $authored));

        $this->assertNotNull($entry->fresh()->published_version);
        clearstatcache();
        $this->assertSame($authored, $disk->get('page/home.tsx'));
        $this->assertSame($before, filemtime($disk->path('page/home.tsx')));
    }

    public function test_an_editor_publish_still_writes_its_placement_file(): void
    {
        // The other half of the rule: only a body read from its own file skips the mirror; a save is its own publish and
        // lands the git-trackable file (ADR-0165).
        $disk = $this->mirrorOverScannedTree();
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'title' => 'Home']);
        $body = [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Edited']], 'dynamic' => false]];
        app(EntryPublication::class)->write($entry, $body);

        app(EntryPublication::class)->publishWritten($entry->fresh());

        $path = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry->fresh())->pathFor($entry->fresh());
        $this->assertTrue($disk->exists($path), 'an editor publish no longer writes its file');
        $this->assertStringContainsString('Edited', $disk->get($path));
    }

    public function test_a_registered_entry_is_born_published_with_a_pin_and_a_compiled_artifact(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->published_version, 'a disk-registered entry was left without a Publication Pin');
        $this->assertFalse(app(EntryPublication::class)->state($entry)->draftPending);
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertStringContainsString('Registered from disk.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_a_rescan_is_idempotent_the_pin_and_the_history_do_not_move(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $pin = (string) $this->guide()->published_version;

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertSame($pin, (string) $this->guide()->published_version);
        $this->assertSame(1, $this->versionRows($this->guide()));
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'guide')->count());
    }

    public function test_a_failure_while_pinning_surfaces_leaves_no_row_and_a_rescan_registers_it(): void
    {
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('a failure while pinning was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($this->guide(), 'a failed publication left a pin-less row');
        $this->assertSame(0, (int) \DB::table(Beam::table('versions'))->count(), 'a failed publication left a snapshot');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNotNull($this->guide()->published_version, 'a rescan could not register the entry');
    }

    public function test_a_rescan_repairs_a_pinless_registered_row_that_has_no_history(): void
    {
        // What register-from-disk left behind before entries were born published: a body, no pin, no history.
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nRegistered from disk."));
        $this->assertNull($entry->fresh()->published_version);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNotNull($entry->fresh()->published_version, 'a rescan left a pin-less row pin-less');
    }

    public function test_a_rescan_never_publishes_a_pinless_row_that_has_history_it_is_an_unpublished_draft(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nA draft."));
        app(VersionStore::class)->snapshot(BeamParticle::find($entry->fresh()->particle_id), 'draft');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNull($entry->fresh()->published_version, 'a rescan published an unpublished draft');
    }

    public function test_registering_without_the_version_store_refuses_before_writing_anything(): void
    {
        Schema::drop(Beam::table('versions'));

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('an entry was registered with no version store to pin it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vendor:publish --tag=beam-migrations --tag=beam-ux-migrations', $e->getMessage());
        }

        $this->assertNull($this->guide(), 'a refused registration left a row behind');
    }

    public function test_a_changed_source_on_rescan_publishes_a_new_version_and_the_artifact_follows_the_pin(): void
    {
        // review-r1 (2026-10-16): a pristine row whose disk source changed is re-asserted. That rewrote the body and
        // force-compiled it under the OLD pin, so the served artifact no longer matched the published version.
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $first = (string) $this->guide()->published_version;

        $this->writeGuide("# Guide\n\nChanged on disk.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertContains('page/guide.mdx', $result['reasserted']);
        $this->assertNotSame($first, (string) $entry->published_version, 'a changed source left the pin on the old version');
        $this->assertSame(2, $this->versionRows($entry));
        $this->assertFalse(app(EntryPublication::class)->state($entry)->draftPending);
        $this->assertStringContainsString('Changed on disk.', (string) app(EntryArtifactStore::class)->read($entry));
    }

    public function test_a_changed_source_without_the_version_store_refuses_before_touching_the_row(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $before = $this->guide();
        $body = app(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::class)->resolve($before)->read((string) $before->particle_id)?->body;
        Schema::drop(Beam::table('versions'));
        $this->writeGuide("# Guide\n\nChanged on disk.\n");

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('a re-assert wrote with no version store to publish it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vendor:publish --tag=beam-migrations --tag=beam-ux-migrations', $e->getMessage());
        }

        $after = $this->guide();
        $this->assertSame((string) $before->asserted_hash, (string) $after->asserted_hash, 'the provenance hash moved');
        $this->assertSame($body, app(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::class)->resolve($after)->read((string) $after->particle_id)?->body, 'the body was rewritten');
    }

    public function test_hydrating_an_empty_entry_is_born_published(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n"));

        $this->assertNotNull($entry->fresh()->published_version, 'a hydrated entry was left without a Publication Pin');
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertStringContainsString('Hydrated.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_a_failure_while_pinning_a_hydration_rolls_it_back_and_a_retry_hydrates(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->failNextPinSave();

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n");
            $this->fail('a failure while pinning a hydration was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($entry->fresh()->particle_id, 'a failed hydration left a body behind');
        $this->assertSame(0, (int) \DB::table(Beam::table('versions'))->count());

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry->fresh(), "# Guide\n\nHydrated.\n"));
        $this->assertNotNull($entry->fresh()->published_version);
    }

    public function test_hydrating_an_entry_that_already_has_a_body_changes_nothing(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $entry = $this->guide();
        $pin = (string) $entry->published_version;

        $this->assertFalse($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Other\n"));

        $this->assertSame($pin, (string) $entry->fresh()->published_version);
        $this->assertSame(1, $this->versionRows($entry));
    }

    public function test_a_compile_failure_on_registration_is_collected_while_the_entry_is_still_published(): void
    {
        $this->app->singleton(EntryBodyCompiler::class, fn () => new FailingDiskCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
        FailingDiskCompiler::$attempts = 0;

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertNotNull($entry->published_version, 'a compile failure unpinned the entry');
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertSame(1, FailingDiskCompiler::$attempts, 'the entry was compiled more than once');
        $this->assertArrayHasKey('page/guide.mdx', $result['failed']);
        $this->assertStringContainsString('no toolchain here', $result['failed']['page/guide.mdx']);
    }

    public function test_a_central_entry_under_a_tenant_default_hydrates_on_central_and_a_failed_pin_rolls_its_body_back(): void
    {
        // review-r1 R2 (2026-10-16): EntryPromoter's shape, an entry on `central` while the default is a tenant's. The
        // hydration's transaction is on central; an unbound driver wrote the particle on the default, outside it.
        $this->withCentral();
        $entry = BeamUxEntry::on('central')->create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->failNextPinSave();

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n");
            $this->fail('a failure while pinning a central hydration was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($entry->fresh()->particle_id);
        $this->assertSame([0, 0], $this->rows('particles'), 'a failed central hydration left a particle (central, default)');
        $this->assertSame([0, 0], $this->rows('versions'));

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry->fresh(), "# Guide\n\nHydrated.\n"));

        $this->assertNotNull($entry->fresh()->published_version, 'a central hydration was left unpinned');
        $this->assertSame([1, 0], $this->rows('particles'), 'the body left the entry connection');
        $this->assertSame([1, 0], $this->rows('versions'));
    }

    public function test_a_central_entry_under_a_tenant_default_reasserts_on_central_and_a_failed_pin_rolls_the_rewrite_back(): void
    {
        $this->withCentral();
        $entry = BeamUxEntry::on('central')->create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nRegistered from disk.\n");
        // Pristine, as a disk-registered row is: its provenance hash is taken over the stored body.
        $entry = $entry->fresh();
        $entry->forceFill(\Splicewire\Beam\Ux\Provenance\Provenance::stamp(\Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx'), 'Guide', "# Guide\n\nRegistered from disk.\n", $entry->codec()))->save();
        $pin = (string) $entry->fresh()->published_version;
        $body = $this->centralBody($entry);
        $this->failNextPinSave();

        try {
            app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->reassert($entry->fresh(), 'Guide', "# Guide\n\nChanged on disk.\n", \Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx'));
            $this->fail('a failure while pinning a central re-assert was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame($pin, (string) $entry->fresh()->published_version);
        $this->assertSame($body, $this->centralBody($entry), 'a failed central re-assert kept its rewrite');
        $this->assertSame([1, 0], $this->rows('particles'));
        $this->assertSame([1, 0], $this->rows('versions'));

        $this->assertTrue(app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->reassert($entry->fresh(), 'Guide', "# Guide\n\nChanged on disk.\n", \Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx')));

        $this->assertNotSame($pin, (string) $entry->fresh()->published_version, 'a central re-assert left the old pin');
        $this->assertSame([1, 0], $this->rows('particles'), 'the re-assert left the entry connection or its particle');
        $this->assertSame([2, 0], $this->rows('versions'));
    }

    /** A `central` connection carrying the same migrated schema, beside the (tenant) default. */
    private function withCentral(): void
    {
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $default = \DB::getDefaultConnection();
        \DB::setDefaultConnection('central');
        try {
            $this->createTables();
        } finally {
            \DB::setDefaultConnection($default);
        }
    }

    /** @return array{0: int, 1: int} [central, default] row counts of a beam table */
    private function rows(string $table): array
    {
        return [
            (int) \DB::connection('central')->table(Beam::table($table))->count(),
            (int) \DB::table(Beam::table($table))->count(),
        ];
    }

    private function centralBody(BeamUxEntry $entry): mixed
    {
        return \DB::connection('central')->table(Beam::table('particles'))->where('id', (string) $entry->fresh()->particle_id)->value('payload');
    }

    private function writeGuide(string $body): void
    {
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n".$body);
    }

    private function failNextPinSave(): void
    {
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });
    }

    private function createTables(): void
    {
        // The shipped migrations, so the fixture is a migrated host (provenance included, which the re-assert path reads).
        $ux = dirname(__DIR__).'/database/migrations/shared/';
        $beam = dirname((new \ReflectionClass(BeamParticle::class))->getFileName(), 3).'/database/migrations/shared/';
        foreach (['create_beam_ux_entries_table', 'add_provenance_to_beam_ux_entries_table', 'add_published_version_to_beam_ux_entries_table'] as $stub) {
            (require $ux.$stub.'.php.stub')->up();
        }
        foreach (['create_beam_particles_table', 'create_beam_versions_table'] as $stub) {
            (require $beam.$stub.'.php.stub')->up();
        }
    }
}

/** A compiler with no toolchain: it echoes the source back as a module. */
class DiskFakeCompiler implements EntryBodyCompiler
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

/** A compiler that always fails, counting its attempts. */
class FailingDiskCompiler implements EntryBodyCompiler
{
    public static int $attempts = 0;

    public function handles(BeamUxEntry $entry): bool
    {
        return true;
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        self::$attempts++;

        throw \Splicewire\Beam\Ux\Compile\CompilationFailed::for($entry, 'no toolchain here');
    }
}
