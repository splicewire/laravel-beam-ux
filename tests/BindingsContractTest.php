<?php

namespace Splicewire\Beam\Ux\Tests;

class BindingsContractTest extends TestCase
{
    public function test_committed_typescript_matches_php_and_the_consumer_when_bound(): void
    {
        $autoload = collect(get_included_files())->first(fn ($file) => str_ends_with($file, '/vendor/autoload.php'));
        $previous = getenv('BEAM_UX_AUTOLOAD');
        putenv('BEAM_UX_AUTOLOAD='.$autoload);
        ob_start();
        try {
            require dirname(__DIR__).'/scripts/export-bindings.php';
            $generated = ob_get_contents();
        } finally {
            ob_end_clean();
            $previous === false ? putenv('BEAM_UX_AUTOLOAD') : putenv('BEAM_UX_AUTOLOAD='.$previous);
        }
        $this->assertSame(file_get_contents(__DIR__.'/Fixtures/binding-types.ts'), $generated);
        if ($consumer = getenv('EP_BINDING_TYPES_FIXTURE')) {
            $this->assertFileExists($consumer);
            $this->assertSame(file_get_contents($consumer), $generated);
        }
    }
}
