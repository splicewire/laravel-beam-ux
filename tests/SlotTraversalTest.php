<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Access\Gate;
use Splicewire\Beam\Ux\Canvas\ViewGateFilter;
use Splicewire\Beam\Ux\JsonDoc\JsonDocIdRematcher;
use Splicewire\Beam\Ux\Manifest\BodyNodeDiff;

class SlotTraversalTest extends TestCase
{
    public function test_view_gates_strip_slot_content_and_identity_diff_sees_slot_moves(): void
    {
        $child = ['kind' => 'block', 'name' => 'p', 'id' => 'child', 'isComponent' => false, 'dynamic' => false, 'props' => [['name' => 'data-view-gate', 'kind' => 'string', 'value' => 'private']], 'children' => [['kind' => 'text', 'value' => 'secret']]];
        $body = [['kind' => 'block', 'name' => 'Card', 'id' => 'card', 'props' => [], 'children' => [], 'slots' => ['media' => [$child]]]];
        $gate = \Mockery::mock(Gate::class);
        $gate->shouldReceive('allows')->once()->with('entitlement:private')->andReturn(false);
        $this->assertSame([], (new ViewGateFilter($gate))->filter($body)[0]['slots']['media']);
        $parsed = $body;
        unset($parsed[0]['id'], $parsed[0]['slots']['media'][0]['id']);
        $this->assertSame('child', (new JsonDocIdRematcher)->rematch($body, $parsed)[0]['slots']['media'][0]['id']);
        $moved = $body;
        $moved[0]['slots'] = ['footer' => [$child]];
        $this->assertArrayHasKey('child', (new BodyNodeDiff)->changedIds($body, $moved));
    }
}
