<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;

/** A missing property, distinct from a malformed schema/reference or any other declaration failure. */
final class MissingBindingField extends ValidationException
{
    public static function named(string $path): self
    {
        return self::withMessages(['body' => 'The field ['.$path.'] is not declared by the offered Collection.']);
    }
}
