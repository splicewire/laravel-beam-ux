<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;

/** Absence from the explicit offer only. Authorization, query and registry failures keep their own type. */
final class MissingProfileCollection extends ValidationException
{
    public readonly string $collection;

    public static function named(string $name): self
    {
        $error = self::withMessages(['repeat.collection' => 'The Collection is not offered by this Manifest Profile.']);
        $error->collection = $name;

        return $error;
    }
}
