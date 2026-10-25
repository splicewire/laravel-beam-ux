<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Ux\Data\CanvasBodyInputData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData;
use Splicewire\Beam\Ux\JsonDoc\JsonProp;

class JsonPropValidationTest extends TestCase
{
    public static function scalars(): array
    {
        return ['text' => ['string', 'A title'], 'empty text' => ['string', ''],
            'integer' => ['number', 42], 'decimal' => ['number', 1.5],
            'true' => ['boolean', true], 'false' => ['boolean', false], 'null' => ['string', null]];
    }

    #[DataProvider('scalars')]
    public function test_declared_scalar_values_validate_directly_and_inside_canvas_inputs(string $kind, mixed $value): void
    {
        $prop = ['name' => 'title', 'kind' => $kind, 'value' => $value];
        $this->assertSame($value, JsonProp::validateAndCreate($prop)->value);
        $body = [['kind' => 'block', 'name' => 'div', 'isComponent' => false,
            'dynamic' => false, 'props' => [$prop], 'children' => []]];
        foreach ([CanvasBodyInputData::class, EntryCanvasBindingsInputData::class] as $input) {
            $input::validate(['body' => $body]);
            $this->assertSame($value, $input::validateAndCreate(['body' => $body])->body[0]->props[0]->value);
        }
    }

    public static function invalidValues(): array
    {
        return ['list' => [[]], 'dictionary' => [['bad' => 'value']], 'object' => [(object) ['bad' => 'value']]];
    }

    #[DataProvider('invalidValues')]
    public function test_non_scalar_values_are_validation_refusals(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        JsonProp::validateAndCreate(['name' => 'title', 'kind' => 'string', 'value' => $value]);
    }

    public function test_value_must_be_present(): void
    {
        $this->expectException(ValidationException::class);
        JsonProp::validateAndCreate(['name' => 'title', 'kind' => 'string']);
    }

    public static function unsafeProps(): array
    {
        return ['name' => ['bad name', 'string', 'ok'], 'unsafe number' => ['count', 'number', 9007199254740992],
            'field path' => ['title', 'field', 'bad path'], 'prop reference' => ['title', 'prop', 'bad path']];
    }

    #[DataProvider('unsafeProps')]
    public function test_constructor_guards_still_refuse_unsafe_props(string $name, string $kind, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonProp::validateAndCreate(compact('name', 'kind', 'value'));
    }
}
