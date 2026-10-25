<?php

namespace Splicewire\Beam\Ux\Bindings;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;

/** ADR-0229 d2/d7/d9: a root Collection or a nested relation, with its own empty subtree. */
#[TypeScript]
final class RepeatData extends BeamData
{
    /** @param list<JsonNode> $empty */
    public function __construct(
        #[Optional]
        public ?string $collection = null,
        #[Optional]
        public ?string $relation = null,
        #[Optional]
        public ?RepeatQueryData $query = null,
        #[DataCollectionOf(JsonNode::class)]
        #[Optional]
        public array $empty = [],
    ) {
        if (($collection === null) === ($relation === null)) {
            throw new \InvalidArgumentException('A Repeat requires exactly one Collection or relation.');
        }
        if ($collection !== null && ! preg_match('/\A[a-z][a-z0-9-]*\z/', $collection)) {
            throw new \InvalidArgumentException('A Repeat must name a declared Collection.');
        }
        if ($relation !== null) {
            FieldPath::assert($relation);
        }
    }
}
