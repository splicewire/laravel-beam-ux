<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Ux\Manifest\BodyNodeDiff;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d8 — a save refuses only the nodes it ADDS or CHANGES vs the Working Head, and "changed" is
 * decided by CONTENT per id (ADR-0227 security condition): the same id with different own content, a
 * duplicated id, and a node moved under a new parent are all changes. A node's signature therefore
 * excludes its children (each a node in its own right) and carries its parent id (so a move is a change).
 */
class BodyNodeDiffTest extends TestCase
{
    private function block(string $name, string $id, array $children = [], array $props = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => false, 'props' => $props, 'children' => $children, 'dynamic' => false, 'id' => $id];
    }

    public function test_an_added_node_is_changed_and_an_unchanged_sibling_is_not(): void
    {
        $wh = [$this->block('div', 'd1')];
        $in = [$this->block('div', 'd1'), $this->block('p', 'p1')];

        $ids = (new BodyNodeDiff)->changedIds($wh, $in);
        $this->assertArrayHasKey('p1', $ids);
        $this->assertArrayNotHasKey('d1', $ids);
    }

    public function test_a_content_change_is_detected_not_just_id_presence(): void
    {
        $wh = [$this->block('div', 'd1', props: [['name' => 'class', 'kind' => 'string', 'value' => 'ok']])];
        $in = [$this->block('div', 'd1', props: [['name' => 'class', 'kind' => 'string', 'value' => 'HACKED']])];

        $this->assertArrayHasKey('d1', (new BodyNodeDiff)->changedIds($wh, $in));
    }

    public function test_an_unchanged_body_flags_nothing(): void
    {
        $wh = [$this->block('div', 'd1', [$this->block('p', 'p1')])];
        $in = [$this->block('div', 'd1', [$this->block('p', 'p1')])];

        $this->assertSame([], (new BodyNodeDiff)->changedIds($wh, $in));
    }

    public function test_a_duplicated_id_is_flagged(): void
    {
        $wh = [$this->block('div', 'd1')];
        $in = [$this->block('div', 'd1'), $this->block('span', 'd1')]; // id reused by an attacker

        $this->assertArrayHasKey('d1', (new BodyNodeDiff)->changedIds($wh, $in));
    }

    public function test_a_node_moved_under_a_new_parent_is_flagged(): void
    {
        // p1 (same id + own content) moves from under d1 to under d2.
        $wh = [$this->block('div', 'd1', [$this->block('p', 'p1')]), $this->block('div', 'd2')];
        $in = [$this->block('div', 'd1'), $this->block('div', 'd2', [$this->block('p', 'p1')])];

        $this->assertArrayHasKey('p1', (new BodyNodeDiff)->changedIds($wh, $in));
    }
}
