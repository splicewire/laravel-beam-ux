<?php

namespace Splicewire\Beam\Ux\Components;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\JsonDoc\ComponentReference;

/** Stored on the sole root block, so the body and interface have exactly one version history. */
#[TypeScript]
class ComponentDefinitionData extends BeamData
{
    /** @param list<ComponentPropData> $props */
    public function __construct(
        public ?string $name = null,
        #[DataCollectionOf(ComponentPropData::class)]
        public array $props = [],
    ) {
        if ($name !== null) {
            ComponentReference::assert($name);
            if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $name) !== 1 || $name === 'Slot') {
                throw new \InvalidArgumentException('A component name must be PascalCase and cannot be Slot.');
            }
        }
        if (! array_is_list($props)) {
            throw new \InvalidArgumentException('Component props must be a list.');
        }
        $names = [];
        foreach ($props as $prop) {
            if (! $prop instanceof ComponentPropData || in_array($prop->name, $names, true)) {
                throw new \InvalidArgumentException('Component props require unique declared names.');
            }
            $names[] = $prop->name;
        }
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->props as $prop) {
            $properties[$prop->name] = $prop->schema();
            if ($prop->required && $prop->default === null) {
                $required[] = $prop->name;
            }
        }

        return ['type' => 'object', ...($properties === [] ? [] : ['properties' => $properties]), 'required' => $required, 'additionalProperties' => false];
    }
}
