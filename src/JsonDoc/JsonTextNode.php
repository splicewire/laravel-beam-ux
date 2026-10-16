<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** ADR-0228 d2 — a text node: a run of literal text. The twin of `json.ts`'s `JsonText`. */
#[TypeScript]
class JsonTextNode extends JsonNode
{
    public function __construct(
        public string $value,
        string $kind = 'text',
    ) {
        parent::__construct($kind);
    }
}
