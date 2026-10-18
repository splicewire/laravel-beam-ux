<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use PHPUnit\Framework\TestCase;

class InitialBodyContractTest extends TestCase
{
    public function test_default_body_contract_is_valid_and_matches_the_js_consumer(): void
    {
        $json = file_get_contents(__DIR__.'/../../resources/editor/default-trees.json');
        $trees = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(8, $trees);
        foreach ($trees as $tree) {
            $this->assertTrue(\Splicewire\Beam\Ux\Codec\JsonDocShape::is($tree));
        }
        if ($consumer = getenv('EP_EDITOR_DEFAULTS_FIXTURE')) {
            $this->assertFileExists($consumer);
            $this->assertSame($json, file_get_contents($consumer), 'Beam JS defaults must match the server-owned contract.');
        }
    }
}
