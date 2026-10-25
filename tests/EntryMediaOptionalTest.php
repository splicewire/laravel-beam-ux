<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Ux\Media\EntryMedia;

class EntryMediaOptionalTest extends TestCase
{
    public function test_media_operations_are_absent_when_the_host_did_not_bind_the_media_provider(): void
    {
        $this->assertFalse(EntryMedia::available());
        $operations = app(ParticleOperationRegistry::class);
        $this->assertFalse($operations->has('beam-ux-entry', 'media'));
        $this->assertFalse($operations->has('beam-ux-entry', 'upload-image'));
    }

    public function test_image_choice_projection_is_generated_from_the_owning_data_classes(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, __DIR__.'/../scripts/export-entry-images.php']);
        $process->mustRun();
        $this->assertSame(file_get_contents(__DIR__.'/Fixtures/entry-images.generated.ts'), $process->getOutput());
    }
}
