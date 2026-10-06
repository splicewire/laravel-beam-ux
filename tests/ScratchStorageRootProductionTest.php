<?php

namespace Splicewire\Beam\Ux\Tests;

/**
 * build.qa: a scratch root set on a production host would send every body mirror and compiled artifact to a temp
 * directory, content that seems to save and is gone after a reboot. In production it is ignored.
 */
class ScratchStorageRootProductionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
        $app['config']->set('beam.ux.scratch_storage_root', sys_get_temp_dir().'/beam-scratch-prod-'.uniqid());
    }

    public function test_a_scratch_root_is_ignored_in_production(): void
    {
        $this->assertTrue($this->app->isProduction());
        $this->assertNotSame('beam-scratch', config('beam.ux.storage.disk'));
        $this->assertNotSame('beam-scratch', config('beam.ux.compile.disk'));
        $this->assertNull(config('filesystems.disks.beam-scratch'));
    }
}
