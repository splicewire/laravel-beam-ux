<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;

/** Shared fresh native fixture only; no inherited test methods or shared database state. */
abstract class AuthoredComponentNativeTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        foreach (['create_beam_particles_table', 'create_beam_versions_table', 'create_activity_log_table'] as $name) {
            (require $core.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        foreach (['create_beam_ux_entries_table', 'add_page_settings_to_beam_ux_entries_table', 'add_child_fields_to_beam_ux_entries_table', 'add_record_page_to_beam_ux_entries_table', 'add_component_kind_to_beam_ux_entries_table'] as $name) {
            (require dirname(__DIR__).'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        $this->assertSame('sqlite', Schema::getConnection()->getDriverName());
        $this->assertSame(':memory:', Schema::getConnection()->getDatabaseName());
        Storage::fake('component-artifacts');
        Storage::fake('component-mirror');
        config(['beam.ux.compile.disk' => 'component-artifacts', 'beam.ux.storage.mirror_disk' => 'component-mirror']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(workingDirectory: __DIR__.'/Fixtures/entry-body-host'));
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }
}
