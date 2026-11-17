<?php

namespace Splicewire\Beam\Ux\Schema;

use BackedEnum;
use ReflectionProperty;
use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Schemastud\DataSchemas\Strategies\SchemaStrategy;
use Schemastud\DataSchemas\Strategies\SchemaStrategyContext;
use Schemastud\DataSchemas\Text\IdentitySchemaText;

/**
 * localization 23c: projects {@see EnumValueLabels}. For a property whose schema already carries `enum` (from its `In`
 * rule), it adds `enumNames`: each value's enum case label, through the bound SchemaText (beam's ladder in a beam host,
 * the declared label otherwise), the path an enum-typed property's `enumNames` already take. @schemastud/seam lifts
 * `enumNames` into `ui:enumNames`, so the form's options read them.
 *
 * It runs after the validation-attribute strategy (appended to `data-schemas.strategies` at boot), so `enum` is set.
 * It adds nothing when a value names no case, or when the schema has no `enum`: a partial or misaligned `enumNames`
 * would label the wrong option.
 */
final class EnumValueLabelsStrategy implements SchemaStrategy
{
    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array
    {
        $attributes = $property->getAttributes(EnumValueLabels::class);
        if ($attributes === [] || ! isset($schema['enum']) || ! is_array($schema['enum']) || $schema['enum'] === []) {
            return $schema;
        }

        /** @var class-string<BackedEnum&ProvidesEnumLabel> $enum */
        $enum = $attributes[0]->newInstance()->enum;
        if (! is_subclass_of($enum, BackedEnum::class) || ! is_subclass_of($enum, ProvidesEnumLabel::class)) {
            return $schema;
        }

        $text = IdentitySchemaText::current();
        $names = [];
        foreach ($schema['enum'] as $value) {
            $case = is_string($value) || is_int($value) ? $enum::tryFrom($value) : null;
            if (! $case instanceof ProvidesEnumLabel) {
                return $schema;
            }
            $names[] = $text->enumCaseLabel($case, $case->label());
        }

        $schema['enumNames'] = $names;

        return $schema;
    }
}
