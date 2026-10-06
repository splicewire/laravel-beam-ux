<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Integrator 08:33Z (row 13355b04): a "dry run" of a body-writing seed against a SCRATCH database still wrote the live
 * checkout's `storage/app`: the default Stacked driver mirrors each body to `storage/app/<particle-id>`, and compiled
 * artifacts land under `storage/app/beam-ux/`, whatever database the connection names (measured on the flagship: 30
 * mirror files from a scratch run, removed by hand). `BEAM_SCRATCH_STORAGE_ROOT` (`beam.ux.scratch_storage_root`) moves
 * every beam-ux disk a run writes to under one scratch directory, so a rehearsal touches nothing in the checkout.
 */
class ScratchStorageRootTest extends TestCase
{
    private string $root;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $this->root = sys_get_temp_dir().'/beam-scratch-'.uniqid();
        $app['config']->set('beam.ux.scratch_storage_root', $this->root);
        $app['config']->set('beam.ux.storage.mirror_disk', 'local');
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_every_disk_a_run_writes_resolves_under_the_scratch_root(): void
    {
        foreach (['beam.ux.storage.disk', 'beam.ux.compile.disk', 'beam.ux.storage.mirror_disk'] as $key) {
            $this->assertSame('beam-scratch', config($key), $key);
        }
        $this->assertSame($this->root, config('filesystems.disks.beam-scratch.root'));

        Storage::disk(config('beam.ux.storage.disk'))->put('probe', 'x');
        $this->assertFileExists($this->root.'/probe');
        $this->assertFileDoesNotExist(storage_path('app/probe'));
    }

    public function test_a_compiled_artifact_lands_under_the_scratch_root(): void
    {
        $entry = new BeamUxEntry(['slug' => 'x', 'type' => 'page', 'format' => 'mdx']);
        $entry->id = '01scratchprobe';
        app(EntryArtifactStore::class)->put($entry, 'export default () => null;');

        $this->assertNotSame([], glob($this->root.'/beam-ux/artifacts/01scratchprobe/*') ?: []);
    }
}
