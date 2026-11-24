<?php

namespace Splicewire\Beam\Ux\Schema;

use Attribute;
use BackedEnum;

/**
 * localization 23c: a STRING property whose allowed values are the backing values of a labelled enum (for example
 * `BeamUxEntryInputData::$type`, `In(UxType::CREATABLE)`, which are {@see \Splicewire\Beam\Ux\Type\UxType} values). The
 * property's schema then carries `enumNames` beside its `enum`, each the enum case's label resolved for the reader,
 * the same text an enum-typed property projects. {@see EnumValueLabelsStrategy} projects it. The property's shape,
 * type and validation are unchanged.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class EnumValueLabels
{
    /** @param  class-string<BackedEnum&\Schemastud\DataSchemas\Contracts\ProvidesEnumLabel>  $enum */
    public function __construct(public string $enum) {}
}
