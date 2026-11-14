<?php

namespace Splicewire\Beam\Ux\Localization;

use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedValidationException;

/**
 * Several coded refusals on ONE field (localization 23A; range review B10 U1-U3): a canvas body refused node by
 * node, or a schema registration refused page by page. Each message is its case's localized template with its
 * parameters, and `codes[$field]` lists each refusal's code in the same order. It is still a
 * {@see \Illuminate\Validation\ValidationException}, so the per-body validation shape (`errors.body[]`) is unchanged.
 */
final class CodedRefusalList extends CodedValidationException
{
    /**
     * @param  non-empty-list<array{0: CodedError, 1?: array<string, scalar|null>}>  $refusals
     */
    public static function on(string $field, array $refusals): static
    {
        $messages = [];
        $codes = [];
        foreach ($refusals as $refusal) {
            [$error, $params] = [$refusal[0], $refusal[1] ?? []];
            $messages[] = $error->message($params);
            $codes[] = $error->code();
        }

        $exception = self::withMessages([$field => $messages]);
        $exception->codes = [$field => $codes];

        return $exception;
    }
}
