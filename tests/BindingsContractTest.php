<?php

namespace Splicewire\Beam\Ux\Tests;

class BindingsContractTest extends TestCase
{
    public function test_executable_binding_fixture_is_generated_by_the_real_php_printer(): void
    {
        $document = json_decode(file_get_contents(__DIR__.'/Fixtures/binding-document.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = \Splicewire\Beam\Ux\Codec\JsonDocPrinter::printModule($document);
        $this->assertSame($expected, file_get_contents(__DIR__.'/Fixtures/binding-module.tsx.txt'));
        if ($consumer = getenv('EP_BINDING_MODULE_FIXTURE')) {
            $this->assertSame($expected, file_get_contents($consumer));
        }
    }

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
