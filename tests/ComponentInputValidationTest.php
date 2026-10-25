<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Ux\Components\ComponentDefinitionData;
use Splicewire\Beam\Ux\Components\ComponentPropData;
use Splicewire\Beam\Ux\Data\CanvasBodyInputData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;

class ComponentInputValidationTest extends TestCase
{
    public static function defaults(): array
    {
        return ['text' => ['text', 'Title'], 'empty text' => ['text', ''], 'number' => ['number', 42],
            'decimal' => ['number', 1.5], 'true' => ['toggle', true], 'false' => ['toggle', false],
            'null' => ['text', null], 'image' => ['image', '/image.png'], 'link' => ['link', '/page']];
    }

    #[DataProvider('defaults')]
    public function test_component_defaults_and_empty_choices_match_the_declared_shape(string $type, mixed $default): void
    {
        $data = ComponentPropData::validateAndCreate(['name' => 'title', 'type' => $type, 'default' => $default, 'choices' => []]);
        $this->assertSame($default, $data->default);
        $this->assertSame([], $data->choices);
    }

    public static function documents(): array
    {
        $block = ['kind' => 'block', 'name' => null, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [], 'slots' => []];

        return ['empty text' => [['kind' => 'text', 'value' => '']],
            'prop text' => [['kind' => 'text', 'value' => '', 'prop' => 'title']],
            'empty opaque source' => [['kind' => 'opaque', 'reason' => 'expression', 'source' => '']],
            'empty block lists' => [$block],
            'empty definition props' => [[...$block, 'component' => ['name' => 'Card', 'props' => []]]]];
    }

    #[DataProvider('documents')]
    public function test_empty_node_values_and_lists_revalidate_without_changing_constructor_shapes(array $node): void
    {
        foreach ([CanvasBodyInputData::class, EntryCanvasBindingsInputData::class] as $class) {
            $class::validate(['body' => [$node]]);
            $this->assertInstanceOf(JsonNode::class, $class::validateAndCreate(['body' => [$node]])->body[0]);
        }
    }

    public function test_optional_component_defaults_remain_optional(): void
    {
        $prop = ComponentPropData::validateAndCreate(['name' => 'title', 'type' => 'text']);
        $this->assertNull($prop->default);
        $this->assertSame([], $prop->choices);
        $this->assertSame([], ComponentDefinitionData::validateAndCreate(['name' => 'Card'])->props);
    }

    public static function invalidProps(): array
    {
        return ['array default' => [['name' => 'title', 'type' => 'text', 'default' => []]],
            'object default' => [['name' => 'title', 'type' => 'text', 'default' => (object) ['bad' => true]]],
            'choices not list' => [['name' => 'title', 'type' => 'text', 'choices' => ['key' => 'value']]],
            'choices not text' => [['name' => 'title', 'type' => 'text', 'choices' => [123]]]];
    }

    #[DataProvider('invalidProps')]
    public function test_invalid_component_values_are_validation_refusals(array $payload): void
    {
        $this->expectException(ValidationException::class);
        ComponentPropData::validateAndCreate($payload);
    }

    public function test_choice_constructor_still_requires_choices(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComponentPropData::validateAndCreate(['name' => 'title', 'type' => 'choice', 'choices' => []]);
    }
}
