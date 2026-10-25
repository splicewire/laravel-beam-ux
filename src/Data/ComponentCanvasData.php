<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Components\ComponentDefinitionData;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;

#[TypeScript]
class ComponentCanvasData extends BeamData
{
    /** @param list<JsonNode> $body @param array<string, mixed> $bindings */
    public function __construct(
        public string $id,
        public ComponentDefinitionData $definition,
        #[DataCollectionOf(JsonNode::class)]
        public array $body,
        #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType('Record<string, RepeatRecordsData>')]
        public array $bindings = [],
        public ?string $headVersion = null,
        public ?string $publishedVersion = null,
    ) {}
}
