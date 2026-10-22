<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Recording\LocalGit;
use Splicewire\Beam\Ux\Recording\RecordingFailure;

class RecordingIsolationTest extends TestCase
{
    public function test_base_suite_cannot_discover_an_enclosing_source_repository(): void
    {
        // Testbench lives below vendor in the source checkout. Ordinary tests must fail before
        // native Git can discover that enclosing repository; dedicated Git fixtures opt in.
        $this->expectException(RecordingFailure::class);
        new LocalGit(base_path());
    }
}
