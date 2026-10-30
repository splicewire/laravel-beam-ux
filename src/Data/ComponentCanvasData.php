<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Components\ComponentDefinitionData;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;

#[TypeScript, Title('Component canvas')]
class ComponentCanvasData extends BeamData
{
    /** @param list<JsonNode> $body @param array<string, mixed> $bindings */
    public function __construct(
        public string $id,
        public ComponentDefinitionData $definition,
        #[DataCollectionOf(JsonNode::class)]
        public array $body,
        // A typed reference, not a literal: a host's generator emits only the types it reaches along references.
        #[\Spatie\TypeScriptTransformer\Attributes\TypeScriptType('array<string, RepeatRecordsData>')]
        public array $bindings = [],
        public ?string $headVersion = null,
        public ?string $publishedVersion = null,
    ) {}
}
