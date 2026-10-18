<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * ADR-0228 d2 — a block's attribute: a name, a {@see PropKind}, and a scalar value. The declared twin of
 * `json.ts`'s `JsonProp`; `value`'s PHP union projects to `string | number | boolean | null` in TS and to
 * `anyOf` in JSON Schema (laravel-data-schemas 242349b). Generated, so the hand type retires.
 */
#[TypeScript]
class JsonProp extends BeamData
{
    /**
     * A JSX attribute name: an identifier that may carry hyphens (`aria-label`, `data-x`) and one namespace
     * (`xlink:href`). The printer emits the name verbatim, so anything else would be source text.
     */
    public const NAME_PATTERN = '/\A[A-Za-z_$][A-Za-z0-9_$-]*(?::[A-Za-z_$][A-Za-z0-9_$-]*)?\z/';

    /**
     * A literal prop never carries code (EP-07a, launch 2026-10-17): the printer emitted the name and a
     * `number` value raw, so either could put an expression into the module with no `expression` prop. Both
     * are refused here, where a save and the printer both hydrate, rather than escaped at one of them.
     */
    public function __construct(
        public string $name,
        public PropKind $kind,
        public string|int|float|bool|null $value,
    ) {
        if ($kind === PropKind::Field) {
            \Splicewire\Beam\Ux\Bindings\FieldPath::assert($value);
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('The prop name ['.$name.'] is not a JSX attribute name.');
        }

        if ($kind === PropKind::NumberValue && ! self::printsAlike($value)) {
            throw new \InvalidArgumentException('The prop ['.$name.'] is a number prop and must carry a number both printers write alike (a safe integer, or a finite decimal with no exponent and not -0), not '.json_encode($value).'.');
        }
    }

    /**
     * A number the PHP and JS printers write identically. PHP's `json_encode` and JS's `String(n)` disagree
     * on `-0`, on unsafe integers (JS rounds 2^53+1), and on exponent forms (PHP switches at 1e15 and 1e-5,
     * JS at 1e21 and 1e-7), so those are refused rather than reconciled; an integral float prints as its
     * integer, as JS does (review-r1, 2026-10-17).
     */
    private static function printsAlike(mixed $value): bool
    {
        if (is_int($value)) {
            return abs($value) <= 9007199254740991;
        }

        return is_float($value) && is_finite($value)
            && ! ($value === 0.0 && fdiv(1, $value) < 0)
            && preg_match('/\A-?\d+(\.\d+)?\z/', (string) json_encode($value)) === 1;
    }
}
