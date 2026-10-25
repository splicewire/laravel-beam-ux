<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** ADR-0228 d2 — a text node: a run of literal text. The twin of `json.ts`'s `JsonText`. */
#[TypeScript]
class JsonTextNode extends JsonNode
{
    public function __construct(
        public string $value,
        // Pin the generated TS discriminant to the literal `'text'` (see JsonBlockNode); the morph
        // discriminator stays the base's #[PropertyForMorph] `kind`.
        #[LiteralTypeScriptType("'text'")]
        public string $kind = 'text',
        #[Optional]
        public ?string $field = null,
        #[Optional]
        public ?string $prop = null,
    ) {
        parent::__construct($kind);
        if ($prop !== null) {
            ComponentReference::assert($prop);
            if ($field !== null) {
                throw new \InvalidArgumentException('A text node cannot reference both a field and a prop.');
            }
        }
        if ($field !== null) {
            \Splicewire\Beam\Ux\Bindings\FieldPath::assert($field);
        }
    }
}
