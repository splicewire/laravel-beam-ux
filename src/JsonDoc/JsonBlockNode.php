<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d2 — a block node: a JSX element (or a fragment, `name` null), its props, its children and
 * whether it is a component / dynamically bound. The twin of `json.ts`'s `JsonBlock`. `children` is a
 * recursive {@see JsonNode} list, so the morph union is self-referential. The stable `id` (d3) is added
 * here in EP-06; the Block Style (`style` layers, ADR-0003/d4) is still a later slice.
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
        // ADR-0228 d3: a block's stable UUIDv7 id (as in blockdoc). The TSX printer leaves it out of
        // source; wherever source comes back in, the Node TSX Lens reattaches it against the Working Head
        // by content (NodeIdRematcher, d9). Nullable with a null default so a body authored before this
        // slice, and the canvas's own pre-id nodes, still hydrate — the rematch is what makes the invariant
        // ("every block has an id") hold in practice, not the shape.
        #[Optional]
        public ?string $id = null,
        // Pin the generated TS discriminant to the literal `'block'` (the arm's `kind` is always that),
        // not the base's `string`: otherwise a bare JsonBlockNode is not assignable to the JsonNode
        // union and the client guards (isJsonBlock, …) fail to typecheck. The morph discriminator stays
        // the base's #[PropertyForMorph] `kind`; this only narrows the GENERATED type of the arm.
        #[LiteralTypeScriptType("'block'")]
        public string $kind = 'block',
        #[Optional]
        public ?\Splicewire\Beam\Ux\Style\BlockStyleData $style = null,
        #[Optional]
        public ?\Splicewire\Beam\Ux\Bindings\RepeatData $repeat = null,
        #[Optional]
        public ?\Splicewire\Beam\Ux\Bindings\ConditionData $when = null,
        /** @var array<string, list<JsonNode>> */
        #[LiteralTypeScriptType('Record<string, JsonNode[]>')]
        #[Optional]
        public array $slots = [],
        #[Optional]
        public ?\Splicewire\Beam\Ux\Components\ComponentDefinitionData $component = null,
    ) {
        parent::__construct($kind);
        foreach ($slots as $slot => $nodes) {
            ComponentReference::assert($slot);
            if ($slot === 'children' || ! is_array($nodes) || ! array_is_list($nodes)) {
                throw new \InvalidArgumentException('Named slots require a node list and cannot replace children.');
            }
            $this->slots[$slot] = array_map(fn ($node) => $node instanceof JsonNode ? $node : JsonNode::from($node), $nodes);
        }
    }
}
