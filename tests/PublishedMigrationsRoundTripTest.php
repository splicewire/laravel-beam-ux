<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

class PublishedMigrationsRoundTripTest extends TestCase
{
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

    public function test_declared_migrations_migrate_rollback_and_migrate_again(): void
    {
        $package = new Package;
        $package->setBasePath(dirname(__DIR__).'/src');
        (new BeamUxServiceProvider($this->app))->configurePackage($package);
        $migrations = array_map(fn (string $name) => require dirname(__DIR__).'/database/migrations/'.$name.'.php.stub', $package->migrationFileNames);
        $tables = ['beam_ux_entries', 'beam_ux_releases', 'beam_ux_release_members', 'beam_ux_redirects', 'beam_ux_release_recordings', 'beam_ux_preview_grants'];

        // The caller supplies an isolated PostgreSQL database; the ordinary suite uses process-local SQLite.
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), 'Requires a fresh test database: '.$table);
        }
        try {
            foreach ($migrations as $migration) {
                $migration->up();
            }
            foreach ($tables as $table) {
                $this->assertTrue(Schema::hasTable($table), $table);
            }
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            foreach ($tables as $table) {
                $this->assertFalse(Schema::hasTable($table), $table);
            }
            foreach ($migrations as $migration) {
                $migration->up();
            }
            $this->assertTrue(Schema::hasColumn('beam_ux_entries', 'origin'));
            $this->assertTrue(Schema::hasIndex('beam_ux_entries', 'beam_ux_entries_origin_index'));
            $this->assertTrue(Schema::hasColumn('beam_ux_entries', 'component_name'));
        } finally {
            foreach (array_reverse($tables) as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    public function test_provenance_rollback_also_handles_an_absent_index_and_a_repeated_down(): void
    {
        Schema::create('beam_ux_entries', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('origin')->nullable();
            $table->string('asserted_hash')->nullable();
        });
        try {
            $migration = require dirname(__DIR__).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub';
            $migration->down();
            $migration->down();
            $this->assertFalse(Schema::hasColumn('beam_ux_entries', 'origin'));
            $this->assertFalse(Schema::hasColumn('beam_ux_entries', 'asserted_hash'));
        } finally {
            Schema::dropIfExists('beam_ux_entries');
        }
    }
}
