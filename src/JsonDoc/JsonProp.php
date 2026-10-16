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
    public function __construct(
        public string $name,
        public PropKind $kind,
        public string|int|float|bool|null $value,
    ) {}
}
