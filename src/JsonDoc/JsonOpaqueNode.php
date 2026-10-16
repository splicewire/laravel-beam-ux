<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d2/d5 — an Opaque Island: source a block grammar could not lower, carried verbatim. `reason`
 * and `source` are checked for shape only; the lens's round-trip certifies it. The twin of `json.ts`'s
 * `JsonOpaque`.
 */
#[TypeScript]
class JsonOpaqueNode extends JsonNode
{
    public function __construct(
        public OpaqueReason $reason,
        public string $source,
        // Pin the generated TS discriminant to the literal `'opaque'` (see JsonBlockNode); the morph
        // discriminator stays the base's #[PropertyForMorph] `kind`.
        #[LiteralTypeScriptType("'opaque'")]
        public string $kind = 'opaque',
    ) {
        parent::__construct($kind);
    }
}
