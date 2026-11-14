<?php

namespace Splicewire\Beam\Ux\Files;

use Splicewire\Beam\Localization\CodedHttpException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Ux\Localization\CodedConflictHttpException;
use Splicewire\Beam\Ux\Localization\DiagnosedValidationException;

/** Translate only expected reconciliation failures; programming and engine faults retain their status. */
final class FileOperation
{
    public static function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (FileConflict $failure) {
            // localization 23 (coord-lead ruling (a)): the response emit renders the case's localized message and its
            // code; the exception's own raw English is never forwarded. A conflict built from a string (none today) gets
            // the generic case, with its text kept as the diagnostic.
            $error = $failure->error ?? FileError::MdxFileInvalid;
            if ($failure->status === 422) {
                throw $failure->error === null
                    ? DiagnosedValidationException::diagnosed('inspection', $error, $failure)
                    : CodedValidationException::for('inspection', $error, $failure->params);
            }
            if ($failure->status === 503) {
                throw new CodedHttpException(503, $error, $failure->params, $failure);
            }
            throw new CodedConflictHttpException($error, $failure->params, $failure);
        }
    }
}
