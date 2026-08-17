<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Canvas\ViewGateFilter;

/**
 * The server-side enforcement of a JsonDoc body's `data-view-gate` entitlement key — the real
 * security boundary behind `@splicewire/beam-ux/canvas`'s per-element gating (its `data-edit-gate`
 * sibling is a client-side-only cosmetic seal; this is what actually keeps content off the wire).
 */
class ViewGateFilterTest extends TestCase
{
    private function filter(): ViewGateFilter
    {
        return new ViewGateFilter($this->app->make(GateContract::class));
    }

    private function block(array $over = []): array
    {
        return array_merge([
            'kind' => 'block',
            'name' => 'div',
            'isComponent' => false,
            'props' => [],
            'children' => [],
            'dynamic' => false,
        ], $over);
    }

    private function text(string $value): array
    {
        return ['kind' => 'text', 'value' => $value];
    }

    private function gateProp(string $key): array
    {
        return [['name' => ViewGateFilter::ATTR, 'kind' => 'string', 'value' => $key]];
    }

    public function test_a_node_with_no_view_gate_prop_passes_through_untouched(): void
    {
        $doc = [$this->block(['name' => 'p', 'children' => [$this->text('hi')]])];

        $this->assertSame($doc, $this->filter()->filter($doc));
    }

    public function test_a_gated_node_is_dropped_when_the_viewer_lacks_the_key(): void
    {
        Gate::define('entitlement:legal.author', fn ($user = null) => false);

        $doc = [
            $this->block(['name' => 'p', 'children' => [$this->text('public')]]),
            $this->block(['name' => 'section', 'props' => $this->gateProp('legal.author'), 'children' => [$this->text('secret')]]),
        ];

        $out = $this->filter()->filter($doc);

        $this->assertCount(1, $out);
        $this->assertSame('p', $out[0]['name']);
    }

    public function test_a_gated_node_passes_through_when_the_viewer_holds_the_key(): void
    {
        Gate::define('entitlement:legal.author', fn ($user = null) => true);

        $doc = [$this->block(['name' => 'section', 'props' => $this->gateProp('legal.author'), 'children' => [$this->text('secret')]])];

        $out = $this->filter()->filter($doc);

        $this->assertCount(1, $out);
        $this->assertSame('secret', $out[0]['children'][0]['value']);
    }

    public function test_dropping_a_gated_node_drops_its_entire_subtree(): void
    {
        Gate::define('entitlement:legal.author', fn ($user = null) => false);

        $doc = [$this->block([
            'name' => 'section',
            'props' => $this->gateProp('legal.author'),
            'children' => [
                $this->block(['name' => 'p', 'children' => [$this->text('nested, still confidential')]]),
            ],
        ])];

        $this->assertSame([], $this->filter()->filter($doc));
    }

    public function test_a_gate_nested_deep_inside_an_ungated_tree_is_dropped_in_place(): void
    {
        Gate::define('entitlement:legal.author', fn ($user = null) => false);

        $doc = [$this->block([
            'name' => 'div',
            'children' => [
                $this->block(['name' => 'p', 'children' => [$this->text('before')]]),
                $this->block(['name' => 'aside', 'props' => $this->gateProp('legal.author'), 'children' => [$this->text('secret')]]),
                $this->block(['name' => 'p', 'children' => [$this->text('after')]]),
            ],
        ])];

        $out = $this->filter()->filter($doc);
        $childNames = array_column($out[0]['children'], 'name');

        $this->assertSame(['p', 'p'], $childNames);
    }

    public function test_a_literal_null_string_gate_value_is_treated_as_no_gate_not_a_real_key(): void
    {
        // Found live: an entitlement <select> with no selection can serialize as the STRING "null"
        // rather than an empty string or an absent key. Nobody holds an entitlement literally named
        // "null" — treating it as a real gate silently stripped the node for every viewer.
        $doc = [$this->block(['name' => 'h1', 'props' => $this->gateProp('null'), 'children' => [$this->text('Heading')]])];

        $out = $this->filter()->filter($doc);

        $this->assertCount(1, $out);
        $this->assertSame('h1', $out[0]['name']);
    }

    public function test_a_non_jsondoc_body_passes_through_unchanged_a_theme_entrys_keyed_token_object(): void
    {
        $themeBody = ['canvas' => ['accent' => '#fff'], 'site' => ['bg' => '#000']];

        $this->assertSame($themeBody, $this->filter()->filter($themeBody));
    }

    public function test_an_empty_body_passes_through_as_an_empty_array(): void
    {
        $this->assertSame([], $this->filter()->filter([]));
    }
}
