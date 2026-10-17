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
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('The prop name ['.$name.'] is not a JSX attribute name.');
        }

        if ($kind === PropKind::NumberValue && ! ((is_int($value) || is_float($value)) && is_finite((float) $value))) {
            throw new \InvalidArgumentException('The prop ['.$name.'] is a number prop and must carry a finite number, not '.get_debug_type($value).'.');
        }
    }
}
