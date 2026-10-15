<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Storage\DeletableStorageDriver;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Lifecycle\EntryPromoter;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Tests\Fixtures\Tag as FixtureTag;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * A force-deleted entry takes its stored body with it.
 *
 * Measured in ux-demo-convergence step 2 (2026-10-15): the Frame console's create writes a default body
 * (`BeamUxEntryData::afterWrite()`: one `beam_particles` row plus its disk-mirror file), and the probe's
 * cleanup force-deletes the entry. The entry went; the row and the file stayed, with no entry left that
 * referenced them — one pair per run. The `deleted` hook already forgot the compiled artifacts; nothing
 * removed the body.
 *
 * A SOFT delete still keeps the body: that entry can be restored (ArtifactReapTest pins the same rule for
 * artifacts).
 */
class EntryForceDeleteBodyTest extends TestCase
{
    private const CENTRAL = EntryPromoter::CENTRAL_CONNECTION;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.'.self::CENTRAL => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->createTables();
        $this->createTables(self::CENTRAL);

        Storage::fake('artifacts');
        config([
            'beam.ux.compile.disk' => 'artifacts',
            'beam.taxonomy.models.tag' => FixtureTag::class,
        ]);
        $this->app->forgetInstance(EntryArtifactStore::class);

        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    public function test_a_force_delete_deletes_the_bound_body_through_the_entrys_driver(): void
    {
        $driver = $this->recordingDefault();
        $entry = $this->page('doomed', 'particle-1');

        $entry->forceDelete();

        $this->assertSame(['particle-1'], $driver->deleted);
    }

    public function test_a_soft_delete_keeps_the_body_because_the_entry_can_be_restored(): void
    {
        $driver = $this->recordingDefault();
        $entry = $this->page('parked', 'particle-1');

        $entry->delete();

        $this->assertSoftDeleted($entry);
        $this->assertSame([], $driver->deleted);
    }

    public function test_an_unbound_entry_has_no_body_to_delete(): void
    {
        $driver = $this->recordingDefault();

        $this->page('never-authored', null)->forceDelete();

        $this->assertSame([], $driver->deleted);
    }

    /**
     * Every path that binds a body mints its own particle (create, duplicate, promote, reassert), so a
     * shared particle is not expected. If one ever exists, deleting it would take a surviving entry's
     * body, so the hook leaves it — a soft-deleted holder counts, since it can be restored.
     */
    public function test_a_body_another_entry_still_references_is_left_in_place(): void
    {
        $driver = $this->recordingDefault();
        $doomed = $this->page('doomed', 'shared');
        $this->page('holder', 'shared')->delete();

        $doomed->forceDelete();

        $this->assertSame([], $driver->deleted);
    }

    /** The default `Stacked(Particle, Disk)` wiring: the row and the mirror file both go. */
    public function test_with_the_default_driver_the_particle_row_and_its_mirror_file_are_removed(): void
    {
        $disk = (string) config('beam.ux.storage.disk');
        Storage::fake($disk);
        $this->app->forgetInstance(StorageDriverResolver::class);

        $entry = $this->page('console-probe', null);
        $written = app(StorageDriverResolver::class)->resolve($entry)->write('', [], '');
        $entry->forceFill(['particle_id' => $written->key])->saveQuietly();
        $this->assertSame(1, BeamParticle::query()->whereKey($written->key)->count(), 'precondition: the row');
        Storage::disk($disk)->assertExists($written->key);

        $entry->forceDelete();

        $this->assertSame(0, BeamParticle::query()->whereKey($written->key)->count(), 'the particle row stayed behind');
        Storage::disk($disk)->assertMissing($written->key);
    }

    /**
     * `EntryPromoter` writes a central particle and binds it on an `on('central')` entry. Force-deleting
     * that entry while the default connection is another one must delete the CENTRAL particle, and must
     * never touch a row with the same id on the default connection (build-qa 947dd76f HIGH).
     */
    public function test_a_central_entry_deletes_its_central_body_and_never_a_same_id_row_on_the_default(): void
    {
        $this->defaultDriverOnFakeDisk();
        $id = '01a00000-0000-7000-8000-00000000c0de';
        $this->particleRow(self::CENTRAL, $id);
        $this->particleRow(null, $id);
        $entry = $this->page('promoted', $id, self::CENTRAL);
        $default = DB::getDefaultConnection();

        $entry->forceDelete();

        $this->assertSame($default, DB::getDefaultConnection(), 'the default connection was not restored');
        $this->assertSame(0, $this->particleCount(self::CENTRAL, $id), 'the central particle stayed behind');
        $this->assertSame(1, $this->particleCount(null, $id), 'a same-id particle on the default connection was deleted');
    }

    public function test_a_central_entry_sharing_the_particle_keeps_the_central_body(): void
    {
        $this->defaultDriverOnFakeDisk();
        $id = '01a00000-0000-7000-8000-00000000c0df';
        $this->particleRow(self::CENTRAL, $id);
        $doomed = $this->page('promoted', $id, self::CENTRAL);
        $this->page('holder', $id, self::CENTRAL)->delete();

        $doomed->forceDelete();

        $this->assertSame(1, $this->particleCount(self::CENTRAL, $id), 'a central holder lost its body');
    }

    public function test_a_holder_on_another_connection_does_not_keep_a_central_body(): void
    {
        $this->defaultDriverOnFakeDisk();
        $id = '01a00000-0000-7000-8000-00000000c0e0';
        $this->particleRow(self::CENTRAL, $id);
        $this->page('tenant-copy', $id);
        $doomed = $this->page('promoted', $id, self::CENTRAL);

        $doomed->forceDelete();

        $this->assertSame(0, $this->particleCount(self::CENTRAL, $id), 'a holder on the default connection decided the central delete');
    }

    private function recordingDefault(): DeletionRecordingDriver
    {
        $driver = new DeletionRecordingDriver;
        $this->app->forgetInstance(StorageDriverResolver::class);
        app(StorageDriverResolver::class)->register(StorageDriverResolver::DEFAULT, $driver);

        return $driver;
    }

    private function defaultDriverOnFakeDisk(): void
    {
        Storage::fake((string) config('beam.ux.storage.disk'));
        $this->app->forgetInstance(StorageDriverResolver::class);
    }

    private function particleRow(?string $connection, string $id): void
    {
        DB::connection($connection)->table(Beam::table('particles'))->insert([
            'id' => $id, 'payload' => '[]', 'meta' => '{"namespace":""}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function particleCount(?string $connection, string $id): int
    {
        return DB::connection($connection)->table(Beam::table('particles'))->where('id', $id)->count();
    }

    private function page(string $slug, ?string $particleId, ?string $connection = null): BeamUxEntry
    {
        $entry = BeamUxEntry::on($connection)->create([
            'slug' => $slug,
            'type' => UxType::Page->value,
            'realm' => 'site',
            'segment' => $slug,
        ]);
        $entry->forceFill(['particle_id' => $particleId])->saveQuietly();

        return $entry;
    }

    private function createTables(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        $schema->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false);
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
            $table->string('segment')->nullable()->index();
            $table->integer('nav_order')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });

        $schema->create(Beam::table('particles'), function (Blueprint $table) {
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

        $schema->create(Beam::table('versions'), function (Blueprint $table) {
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

        $schema->create('activity_log', function (Blueprint $table) {
            $table->id();
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

        // HasFacets' HasTags concern hooks a `deleted` listener unconditionally (ArtifactReapTest's precedent).
        $schema->create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 512);
            $table->string('slug', 512);
            $table->string('type')->nullable();
            $table->timestamps();
        });

        $schema->create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->uuid('taggable_id');
            $table->string('taggable_type');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }
}

class DeletionRecordingDriver extends RecordingDriver implements DeletableStorageDriver
{
    /** @var array<int, string> */
    public array $deleted = [];

    public function canDelete(): bool
    {
        return true;
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
    }
}
