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

    /**
     * Kept from ChromeColumnsMigrationTest when migration-flatten 06 retired its chrome/name ordering pins (02 Q7: a
     * publication guard stays). package-tools loads vendor migrations when runsMigrations is true; beam-ux's ship
     * publish-only, and StubMigrationsAudit does not read this property.
     */
    public function test_declared_migrations_are_publish_only_and_never_run_from_vendor(): void
    {
        $package = new Package;
        $package->setBasePath(dirname(__DIR__).'/src');
        (new BeamUxServiceProvider($this->app))->configurePackage($package);

        $this->assertNotEmpty($package->migrationFileNames);
        $this->assertFalse($package->runsMigrations);
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

}
