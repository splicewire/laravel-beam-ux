<?php

namespace Splicewire\Beam\Ux\Tests;

/** review-r1: under a cached config the env() in config/beam/ux.php is never read; the process env still applies. */
class ScratchStorageRootFromProcessEnvTest extends TestCase
{
    private string $root;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $this->root = sys_get_temp_dir().'/beam-scratch-env-'.uniqid();
        putenv('BEAM_SCRATCH_STORAGE_ROOT='.$this->root);
        $app['config']->set('beam.ux.scratch_storage_root', null); // as a cached config would hold it
    }

    protected function tearDown(): void
    {
        putenv('BEAM_SCRATCH_STORAGE_ROOT');
        \Illuminate\Support\Facades\File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_an_exported_root_applies_when_the_config_key_is_empty(): void
    {
        $this->assertSame('beam-scratch', config('beam.ux.storage.disk'));
        $this->assertSame($this->root, config('filesystems.disks.beam-scratch.root'));
    }
}
