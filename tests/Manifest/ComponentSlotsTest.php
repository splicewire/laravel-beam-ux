<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Tests\TestCase;

class ComponentSlotsTest extends TestCase
{
    private function block(string $name, array $children = [], array $props = []): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => ctype_upper($name[0]), 'props' => $props, 'children' => $children, 'dynamic' => false];
    }

    private function profile(): array
    {
        return ['x-admits-code' => false, 'doc' => ['admitsChildCategories' => null], 'nodes' => [
            ['name' => 'Card', 'category' => 'block', 'admitsChildCategories' => ['block'], 'attrsSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]], 'slots' => [['name' => 'media', 'admitsChildCategories' => ['image']]]],
            ['name' => 'h3', 'category' => 'block', 'admitsChildCategories' => null, 'admitsText' => true, 'attrsSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]],
            ['name' => 'img', 'category' => 'image', 'admitsChildCategories' => []],
        ]];
    }

    public function test_hosted_accepts_declared_prop_but_refuses_an_undeclared_reference(): void
    {
        $guard = new ProfileLegality;
        $body = [$this->block('h3', [['kind' => 'text', 'value' => '', 'prop' => 'title']], [['name' => 'title', 'kind' => 'prop', 'value' => 'title']])];
        $this->assertSame(['code' => [], 'legality' => []], $guard->violations($body, $this->profile(), null, 'Card'));
        $body[0]['children'][0]['prop'] = 'secret';
        $this->assertNotEmpty($guard->violations($body, $this->profile(), null, 'Card')['code']);
        $this->assertNotEmpty($guard->violations($body, $this->profile())['code']);
    }

    public function test_slots_enforce_categories_and_never_hide_code(): void
    {
        $guard = new ProfileLegality;
        $body = [$this->block('Card')];
        $body[0]['slots'] = ['media' => [$this->block('img')]];
        $this->assertSame(['code' => [], 'legality' => []], $guard->violations($body, $this->profile()));
        $body[0]['slots']['media'] = [$this->block('h3')];
        $this->assertNotEmpty($guard->violations($body, $this->profile())['legality']);
        $body[0]['slots'] = ['unknown' => [['kind' => 'opaque', 'reason' => 'expression', 'source' => '{steal()}']]];
        $result = $guard->violations($body, $this->profile());
        $this->assertNotEmpty($result['code']);
        $this->assertNotEmpty($result['legality']);
    }

    public function test_declared_prop_type_must_fit_its_destination_and_slot_fallback_is_checked(): void
    {
        $guard = new ProfileLegality;
        $profile = $this->profile();
        $profile['nodes'][0]['attrsSchema']['properties']['count'] = ['type' => 'number'];
        $body = [$this->block('h3', [], [['name' => 'title', 'kind' => 'prop', 'value' => 'count']])];
        $this->assertNotEmpty($guard->violations($body, $profile, null, 'Card')['legality']);
        $slot = $this->block('Slot', [$this->block('h3')], [['name' => 'name', 'kind' => 'string', 'value' => 'media']]);
        $this->assertNotEmpty($guard->violations([$slot], $profile, null, 'Card')['legality']);
        $slot['children'] = [$this->block('img')];
        $this->assertSame(['code' => [], 'legality' => []], $guard->violations([$slot], $profile, null, 'Card'));
        $slot['repeat'] = ['empty' => [['kind' => 'opaque', 'reason' => 'expression', 'source' => '{steal()}']]];
        $this->assertNotEmpty($guard->violations([$slot], $profile, null, 'Card')['code']);
    }
}
