<?php

namespace Splicewire\Beam\Ux\Localization;

use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedValidationException;
use Throwable;

/**
 * A coded refusal that replaces a FORWARDED internal message (localization 23; coord-lead ruling (b), 23:32Z).
 *
 * A person reads the case's generic, keyed message. The raw text that used to be forwarded (an internal
 * InvalidArgumentException or TypeError, or the TSX lens's output) is kept on {@see $diagnostic} for logs and tests. It
 * is never rendered and never translated.
 */
class DiagnosedValidationException extends CodedValidationException
{
    /** The raw internal text this refusal replaced. Machine diagnostics, ruling 04. */
    public ?string $diagnostic = null;

    /** @param  array<string, scalar>  $params */
    public static function diagnosed(string $field, CodedError $error, Throwable|string $raw, array $params = []): static
    {
        $exception = static::for($field, $error, $params);
        $exception->diagnostic = $raw instanceof Throwable ? $raw->getMessage() : $raw;

        return $exception;
    }
}
