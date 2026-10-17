<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Splicewire\Beam\Ux\JsonDoc\SourceToBlocks;
use Splicewire\Beam\Ux\JsonDoc\TsxLens;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d9 — SourceToBlocks composes the Node TSX Lens with the id rematch. These tests drive it with
 * a FAKE lens (the real Node lens is exercised by the on-tots integration run), so the lens→rematch
 * wiring is proven here without a Node build: ids survive the round trip, a new node is minted one.
 */
class SourceToBlocksTest extends TestCase
{
    private function lensReturning(array $doc): TsxLens
    {
        return new class($doc) implements TsxLens
        {
            public function __construct(private array $doc) {}

            public function lower(string $source): array
            {
                return $this->doc; // a fake lens: ignores the source, returns a fixed id-less doc
            }
        };
    }

    public function test_it_reattaches_a_working_head_id_onto_the_lowered_doc(): void
    {
        $lens = $this->lensReturning([
            ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'hello']], 'dynamic' => false],
        ]);
        $workingHead = [
            ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'hello']], 'dynamic' => false, 'id' => 'p-1'],
        ];

        $out = (new SourceToBlocks($lens))->convert('<p>hello</p>', $workingHead);

        $this->assertSame('p-1', $out[0]['id']);
    }

    public function test_a_node_with_no_working_head_match_is_minted_a_fresh_id(): void
    {
        $lens = $this->lensReturning([
            ['kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'New']], 'dynamic' => false],
        ]);

        $out = (new SourceToBlocks($lens))->convert('<h1>New</h1>', []);

        $this->assertArrayHasKey('id', $out[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $out[0]['id']);
    }
}
