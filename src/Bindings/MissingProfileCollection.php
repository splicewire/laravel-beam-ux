<?php

namespace Splicewire\Beam\Ux\Bindings;

use Splicewire\Beam\Localization\CodedValidationException;

/**
 * Absence from the explicit offer only. Authorization, query and registry failures keep their own type. Still a
 * ValidationException (through CodedValidationException), carrying `binding_collection_not_offered` (localization 23).
 */
final class MissingProfileCollection extends CodedValidationException
{
    public readonly string $collection;

    public static function named(string $name): self
    {
        $error = self::for('repeat.collection', BindingError::CollectionNotOffered);
        $error->collection = $name;

        return $error;
    }
}
