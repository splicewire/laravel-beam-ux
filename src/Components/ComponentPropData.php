<?php

namespace Splicewire\Beam\Ux\Components;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\JsonDoc\ComponentReference;

#[TypeScript, Title('Component prop')]
class ComponentPropData extends BeamData
{
    public const RUNTIME_PROPS = ['children', 'components', 'bindings', 'bindingRuntime', 'styleRegistry', 'className', 'key', 'ref'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            // Override intersection inference for the scalar union, retaining optional defaults.
            'default' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The '.$attribute.' field must be a string, number, boolean or null.');
                }
            }],
            'choices' => ['sometimes', 'array', 'list'],
            'choices.*' => ['string'],
        ];
    }

    /** @param list<string> $choices */
    public function __construct(
        public string $name,
        public ComponentPropType $type,
        public bool $required = false,
        public string|int|float|bool|null $default = null,
        #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType('string[]')]
        public array $choices = [],
    ) {
        ComponentReference::assert($name);
        if (in_array($name, self::RUNTIME_PROPS, true)) {
            throw new \InvalidArgumentException('This prop name belongs to the component runtime or a Slot.');
        }
        if (! array_is_list($choices) || array_filter($choices, fn ($choice) => ! is_string($choice)) !== []
            || ($type === ComponentPropType::Choice && $choices === [])) {
            throw new \InvalidArgumentException('A choice prop requires literal text choices.');
        }
        if ($default !== null && ! $this->accepts($default)) {
            throw new \InvalidArgumentException('A component default must fit its declared prop type.');
        }
    }

    public function accepts(mixed $value): bool
    {
        return match ($this->type) {
            ComponentPropType::Number => is_int($value) || (is_float($value) && is_finite($value)),
            ComponentPropType::Toggle => is_bool($value),
            ComponentPropType::Choice => is_string($value) && in_array($value, $this->choices, true),
            default => is_string($value),
        };
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['x-component-prop-type' => $this->type->value, 'type' => match ($this->type) {
            ComponentPropType::Number => 'number',
            ComponentPropType::Toggle => 'boolean',
            default => 'string',
        }, ...($this->type === ComponentPropType::Choice ? ['enum' => $this->choices] : []),
            ...($this->default === null ? [] : ['default' => $this->default])];
    }
}
