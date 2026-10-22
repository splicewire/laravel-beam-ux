<?php

namespace Splicewire\Beam\Ux\Files;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Translate only expected reconciliation failures; programming and engine faults retain their status. */
final class FileOperation
{
    public static function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (FileConflict $failure) {
            if ($failure->status === 422) {
                throw ValidationException::withMessages(['inspection' => $failure->getMessage()]);
            }
            if ($failure->status === 503) {
                throw new ServiceUnavailableHttpException(null, $failure->getMessage(), $failure);
            }
            throw new ConflictHttpException($failure->getMessage(), $failure);
        }
    }
}
