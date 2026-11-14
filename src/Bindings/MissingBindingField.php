<?php

namespace Splicewire\Beam\Ux\Bindings;

use Splicewire\Beam\Localization\CodedValidationException;

/**
 * A missing property, distinct from a malformed schema/reference or any other declaration failure. Still a
 * ValidationException (through CodedValidationException), carrying `binding_field_undeclared` (localization 23).
 */
final class MissingBindingField extends CodedValidationException
{
    public static function named(string $path): self
    {
        return self::for('body', BindingError::FieldUndeclaredByCollection, ['path' => $path]);
    }
}
