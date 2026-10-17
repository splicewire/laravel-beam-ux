<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Splicewire\Beam\Ux\JsonDoc\JsonDocIdRematcher;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d3/d9 — the TSX Lens parses source into an id-less JsonDoc; this reattaches the Working Head's
 * stable ids by content so a block keeps its id across a source round trip. Modeled on schemastud's
 * NodeIdRematcher PRECEDENT (content match, then a similarity fallback for edited text) but beam-native.
 */
class JsonDocIdRematcherTest extends TestCase
{
    private function block(string $name, ?string $id, array $children = [], array $props = []): array
    {
        $node = ['kind' => 'block', 'name' => $name, 'isComponent' => false, 'props' => $props, 'children' => $children, 'dynamic' => false];
        if ($id !== null) {
            $node['id'] = $id;
        }

        return $node;
    }

    private function text(string $value): array
    {
        return ['kind' => 'text', 'value' => $value];
    }

    public function test_an_unchanged_node_keeps_its_id(): void
    {
        $wh = [$this->block('p', 'p-1', [$this->text('hello')])];
        $parsed = [$this->block('p', null, [$this->text('hello')])];

        $out = (new JsonDocIdRematcher)->rematch($wh, $parsed);
        $this->assertSame('p-1', $out[0]['id']);
    }

    public function test_edited_text_keeps_the_id_through_the_similarity_fallback(): void
    {
        $wh = [$this->block('p', 'p-1', [$this->text('The quick brown fox jumps over the lazy dog')])];
        $parsed = [$this->block('p', null, [$this->text('The quick brown fox jumps over the lazy cat')])];

        $out = (new JsonDocIdRematcher)->rematch($wh, $parsed);
        $this->assertSame('p-1', $out[0]['id']);
    }

    public function test_a_node_with_no_match_gets_a_fresh_uuidv7(): void
    {
        $out = (new JsonDocIdRematcher)->rematch([], [$this->block('p', null, [$this->text('brand new')])]);

        $this->assertArrayHasKey('id', $out[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $out[0]['id']);
    }

    public function test_a_working_head_id_is_spent_only_once(): void
    {
        $wh = [$this->block('p', 'p-1', [$this->text('same')])];
        $parsed = [$this->block('p', null, [$this->text('same')]), $this->block('p', null, [$this->text('same')])];

        $out = (new JsonDocIdRematcher)->rematch($wh, $parsed);
        $this->assertSame('p-1', $out[0]['id']);
        $this->assertNotSame('p-1', $out[1]['id']);
        $this->assertArrayHasKey('id', $out[1]);
    }

    public function test_ids_reattach_into_nested_children(): void
    {
        $wh = [$this->block('div', 'd-1', [$this->block('p', 'p-1', [$this->text('x')])])];
        $parsed = [$this->block('div', null, [$this->block('p', null, [$this->text('x')])])];

        $out = (new JsonDocIdRematcher)->rematch($wh, $parsed);
        $this->assertSame('d-1', $out[0]['id']);
        $this->assertSame('p-1', $out[0]['children'][0]['id']);
    }
}
