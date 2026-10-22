<?php

namespace Splicewire\Beam\Ux\Files;

use RuntimeException;

/** Sanitized failure for the deliberately bounded local-file operations. */
class FileConflict extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
