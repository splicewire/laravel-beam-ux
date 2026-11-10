<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

/**
 * Release members have kinds (config-sections 04, ADR-0243). An existing install's members table, created by the
 * pre-change create stub (kept verbatim as a fixture), is upgraded by add_member_kind_to_beam_ux_release_members_table:
 * every member backfills to an `entry` addressed by its `entry_id`, `entry_id` becomes nullable, and a member of another
 * kind can then exist beside them. Runs on the suite's SQLite, where the nullable change rebuilds the table, and on
 * PostgreSQL when the caller names an isolated database in BEAM_UX_MIGRATION_PG_DATABASE (the seam
 * PublishedMigrationsRoundTripTest uses: this package's suite does not run on pgsql, so `test-full --pgsql` cannot reach it).
 */
class ReleaseMemberKindMigrationTest extends TestCase
{
    private const ALTER = __DIR__.'/../database/migrations/shared/add_member_kind_to_beam_ux_release_members_table.php.stub';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (getenv('BEAM_UX_MIGRATION_PG_DATABASE')) {
            $app['config']->set('database.default', 'migration_pg');
            $app['config']->set('database.connections.migration_pg', [
                'driver' => 'pgsql',
                'host' => getenv('BEAM_UX_MIGRATION_PG_HOST') ?: '127.0.0.1',
                'port' => getenv('BEAM_UX_MIGRATION_PG_PORT') ?: '5432',
                'database' => getenv('BEAM_UX_MIGRATION_PG_DATABASE'),
                'username' => getenv('BEAM_UX_MIGRATION_PG_USER') ?: 'postgres',
                'password' => getenv('BEAM_UX_MIGRATION_PG_PASSWORD') ?: '',
                'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
            ]);
        }
    }

    public function test_the_connection_is_the_one_the_caller_asked_for(): void
    {
        $this->assertSame(getenv('BEAM_UX_MIGRATION_PG_DATABASE') ? 'pgsql' : 'sqlite', DB::connection()->getDriverName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('beam_ux_release_members');
        Schema::dropIfExists('beam_ux_releases');
        (require __DIR__.'/Fixtures/Migrations/legacy-create-beam-ux-releases-table.php.stub')->up();
    }

    private function release(): string
    {
        $id = (string) Str::uuid();
        DB::table('beam_ux_releases')->insert([
            'id' => $id, 'title' => 'R', 'realm' => 'site', 'created_by' => 'user:1', 'edited_by' => 'user:1',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return string the entry id */
    private function legacyMember(string $release, ?string $version = null): string
    {
        $entry = (string) Str::uuid();
        DB::table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release, 'entry_id' => $entry, 'version_id' => $version,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $entry;
    }

    public function test_existing_members_backfill_to_entry_members_addressed_by_their_entry_id(): void
    {
        $release = $this->release();
        $a = $this->legacyMember($release, (string) Str::uuid());
        $b = $this->legacyMember($release);

        (require self::ALTER)->up();

        $rows = DB::table('beam_ux_release_members')->orderBy('entry_id')->get(['member_kind', 'member_ref', 'entry_id']);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('entry', $row->member_kind);
            $this->assertSame($row->entry_id, $row->member_ref);
        }
        $this->assertEqualsCanonicalizing([$a, $b], $rows->pluck('member_ref')->all());

        // The entry-scoped relation every existing reader uses still returns them all.
        $model = BeamUxRelease::query()->findOrFail($release);
        $this->assertCount(2, $model->members()->get());
        $this->assertCount(2, $model->allMembers()->get());
    }

    public function test_after_the_upgrade_a_member_of_another_kind_has_no_entry_and_stays_out_of_the_entry_relation(): void
    {
        $release = $this->release();
        $this->legacyMember($release);
        (require self::ALTER)->up();

        DB::table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release, 'member_kind' => 'probe', 'member_ref' => 'app.meters',
            'entry_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $model = BeamUxRelease::query()->findOrFail($release);
        $this->assertCount(1, $model->members()->get(), 'members() is entry-scoped');
        $this->assertCount(2, $model->allMembers()->get());

        // One member per (release, kind, ref).
        $this->expectException(QueryException::class);
        DB::table('beam_ux_release_members')->insert([
            'id' => (string) Str::uuid(), 'release_id' => $release, 'member_kind' => 'probe', 'member_ref' => 'app.meters',
            'entry_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_upgrade_is_idempotent_and_a_fresh_create_already_has_the_shape(): void
    {
        $release = $this->release();
        $this->legacyMember($release);
        (require self::ALTER)->up();
        (require self::ALTER)->up();
        $this->assertSame(1, DB::table('beam_ux_release_members')->count());

        Schema::dropIfExists('beam_ux_release_members');
        Schema::dropIfExists('beam_ux_releases');
        (require __DIR__.'/../database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        foreach (['member_kind', 'member_ref', 'entry_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('beam_ux_release_members', $column), $column);
        }
        (require self::ALTER)->up(); // a no-op on the fresh shape
        $this->assertTrue(Schema::hasColumn('beam_ux_release_members', 'member_kind'));
    }
}
