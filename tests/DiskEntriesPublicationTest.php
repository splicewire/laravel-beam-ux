<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
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
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;

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

    private function guide(): ?BeamUxEntry
    {
        return BeamUxEntry::query()->where('slug', 'guide')->whereNull('namespace')->first();
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))->where('versionable_id', (string) $entry->fresh()->particle_id)->count();
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
            $table->unsignedInteger('nav_order')->nullable();
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
