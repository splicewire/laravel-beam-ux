<?php

namespace Splicewire\Beam\Ux\Recording;

use RuntimeException;

/** Public messages never include process output, host paths or credentials. */
final class RecordingFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
