<?php

namespace Splicewire\Beam\Ux\Recording;

use RuntimeException;

/** Public messages never include process output, host paths or credentials. */
final class RecordingFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $diagnosticClass = null,
        public readonly ?int $exitStatus = null,
    ) {
        parent::__construct($message);
    }

    /** A bounded receipt field; never contains process output. */
    public function diagnostic(): ?string
    {
        if ($this->diagnosticClass === null) {
            return null;
        }

        return $this->diagnosticClass.($this->exitStatus === null ? '' : ':'.$this->exitStatus);
    }
}
