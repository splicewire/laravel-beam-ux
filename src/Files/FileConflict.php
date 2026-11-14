<?php

namespace Splicewire\Beam\Ux\Files;

use RuntimeException;
use Splicewire\Beam\Localization\CodedError;

/**
 * A local mirror file reconciliation refusal.
 *
 * Localization 23 (coord-lead ruling (a)): it carries its coded case ({@see FileError}). Its OWN message stays the raw
 * English: ThemeReconciliation stores it, and stored prose is 37's. Only the response emit
 * ({@see FileOperation::run()}) renders the case's localized message and its `code`.
 */
class FileConflict extends RuntimeException
{
    /** The coded case, or null for a message passed through as it is (already-keyed upstream text). */
    public readonly ?CodedError $error;

    /**
     * @param  array<string, scalar>  $params
     */
    public function __construct(public readonly string $reason, CodedError|string $message, public readonly int $status = 409, public readonly array $params = [])
    {
        $this->error = $message instanceof CodedError ? $message : null;
        parent::__construct($message instanceof CodedError ? self::english($message, $params) : $message);
    }

    /**
     * The case's raw, declared English (never the request's language, never pseudo-marked): what is stored and logged.
     *
     * @param  array<string, scalar>  $params
     */
    private static function english(CodedError $error, array $params): string
    {
        return strtr($error->defaultMessage(), array_combine(
            array_map(static fn (string $name): string => ':'.$name, array_keys($params)),
            array_map(static fn (mixed $value): string => (string) $value, array_values($params)),
        ));
    }
}
