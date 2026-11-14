<?php

namespace Splicewire\Beam\Ux\Recording;

use RuntimeException;
use Splicewire\Beam\Localization\CodedError;

/**
 * A Release recording failure.
 *
 * Localization 23 (coord-lead ruling (a)): it carries its coded case ({@see RecordingError}). `reason` and
 * {@see diagnostic()} stay RAW: they're machine context, never translated. Its own message is the raw declared
 * English (logs); the response emit ({@see \Splicewire\Beam\Ux\Particle\ReleaseFilesRecordOp}) renders the case's
 * localized message and its `code`.
 */
final class RecordingFailure extends RuntimeException
{
    /** The coded case, or null for a message passed in as a string. */
    public readonly ?CodedError $error;

    /**
     * @param  array<string, scalar>  $params  the case's parameters (machine context, passed through)
     */
    public function __construct(
        public readonly string $reason,
        CodedError|string $message,
        public readonly ?string $diagnosticClass = null,
        public readonly ?int $exitStatus = null,
        public readonly array $params = [],
    ) {
        $this->error = $message instanceof CodedError ? $message : null;
        parent::__construct($message instanceof CodedError ? strtr($message->defaultMessage(), array_combine(
            array_map(static fn (string $name): string => ':'.$name, array_keys($params)),
            array_map(static fn (mixed $value): string => (string) $value, array_values($params)),
        )) : $message);
    }

    public function diagnostic(): ?string
    {
        if ($this->diagnosticClass === null) {
            return null;
        }

        return $this->diagnosticClass.($this->exitStatus === null ? '' : ':'.$this->exitStatus);
    }
}
