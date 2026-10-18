<?php

namespace Splicewire\Beam\Ux\Tests\JsonDoc;

use Splicewire\Beam\Ux\Canvas\ViewGateFilter;
use Splicewire\Beam\Ux\JsonDoc\JsonDocIdRematcher;
use Splicewire\Beam\Ux\Manifest\BodyNodeDiff;
use Splicewire\Beam\Ux\Tests\TestCase;

class BindingTraversalTest extends TestCase
{
    public function test_empty_state_keeps_identity_and_participates_in_the_change_diff(): void
    {
        $empty = ['kind' => 'block', 'id' => 'empty-id', 'name' => 'p', 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Empty']]];
        $body = [['kind' => 'block', 'id' => 'repeat-id', 'name' => 'div', 'repeat' => ['collection' => 'items', 'empty' => [$empty]], 'children' => []]];
        $parsed = $body;
        unset($parsed[0]['id'], $parsed[0]['repeat']['empty'][0]['id']);
        $roundtrip = (new JsonDocIdRematcher)->rematch($body, $parsed);
        $this->assertSame('empty-id', $roundtrip[0]['repeat']['empty'][0]['id'] ?? null);
        $roundtrip[0]['repeat']['empty'][0]['children'][0]['value'] = 'Changed';
        $this->assertArrayHasKey('empty-id', (new BodyNodeDiff)->changedIds($body, $roundtrip));
    }

    public function test_empty_state_view_gates_are_filtered_before_the_body_is_sent(): void
    {
        $gate = \Mockery::mock(\Illuminate\Contracts\Auth\Access\Gate::class);
        $gate->shouldReceive('allows')->with('entitlement:private')->andReturnFalse();
        $body = [['kind' => 'block', 'name' => 'p', 'repeat' => ['collection' => 'items', 'empty' => [[
            'kind' => 'block', 'name' => 'div', 'props' => [['name' => 'data-view-gate', 'kind' => 'string', 'value' => 'private']],
            'children' => [['kind' => 'text', 'value' => 'Private empty state']],
        ]]], 'children' => []]];
        $this->assertSame([], (new ViewGateFilter($gate))->filter($body)[0]['repeat']['empty']);
    }
}
