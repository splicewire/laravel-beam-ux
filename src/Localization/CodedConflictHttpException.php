<?php

namespace Splicewire\Beam\Ux\Localization;

use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\RendersCodedError;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * A 409 whose message is a coded error (localization 23, the laravel-beam-ux sweep).
 *
 * - It IS a {@see ConflictHttpException}, so every `catch (ConflictHttpException)` and type check keeps working.
 * - A request that expects JSON gets `{message}` plus the stable `code`. Any other request renders as before.
 * - It copies beam's {@see \Splicewire\Beam\Localization\CodedNotFoundHttpException} pattern for the 409s beam-ux raises.
 */
class CodedConflictHttpException extends ConflictHttpException
{
    use RendersCodedError;

    /** @param  array<string, scalar>  $params */
    public function __construct(CodedError $error, array $params = [], ?Throwable $previous = null)
    {
        $this->error = $error;
        $this->params = $params;
        parent::__construct($error->message($params), $previous);
    }
}
