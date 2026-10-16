<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d2 — a block node: a JSX element (or a fragment, `name` null), its props, its children and
 * whether it is a component / dynamically bound. The twin of `json.ts`'s `JsonBlock`. `children` is a
 * recursive {@see JsonNode} list, so the morph union is self-referential. The Block Style (`style`
 * layers, ADR-0003) and the stable id (d3) are later slices — not fields here in EP-05.
 */
#[TypeScript]
class JsonBlockNode extends JsonNode
{
    /**
     * @param  array<int, JsonProp>  $props
     * @param  array<int, JsonNode>  $children
     */
    public function __construct(
        public ?string $name,
        public bool $isComponent,
        #[DataCollectionOf(JsonProp::class)]
        public array $props,
        #[DataCollectionOf(JsonNode::class)]
        public array $children,
        public bool $dynamic,
        string $kind = 'block',
    ) {
        parent::__construct($kind);
    }
}
