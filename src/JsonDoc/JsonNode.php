<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Schemastud\DataSchemas\Attributes\MorphUnion;
use Spatie\LaravelData\Contracts\PropertyMorphableData;
use Splicewire\Beam\Data\BeamData;

/**
 * ADR-0228 d2 — the JsonDoc node, a declared morph union keyed on `kind` with three arms: block, text
 * and opaque. `rushing/laravel-data-nav`'s `NavNode` is the precedent; EP-02's {@see MorphUnion}
 * projects this base to a discriminated union in TypeScript (MorphUnionTransformer) and JSON Schema
 * (JsonSchemaGenerator). The generated union replaces `@splicewire/beam-ux/blockdoc/json.ts`'s
 * hand-written `JsonNode` and lets `JsonDocPrinter` stop hand-mirroring the client shape. A JsonDoc is a
 * list of these (see BeamUxEntryBodyInputData::$body). The seven-kind props (field/prop) and the Click
 * Event are later slices (ADR-0229 d3, ADR-0231 d8, ADR-0234 d13); EP-05 is the three original arms.
 */
#[MorphUnion(discriminator: 'kind', arms: [
    'block' => JsonBlockNode::class,
    'text' => JsonTextNode::class,
    'opaque' => JsonOpaqueNode::class,
])]
abstract class JsonNode extends BeamData implements PropertyMorphableData
{
    public function __construct(public string $kind) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['kind'] ?? null) {
            'block' => JsonBlockNode::class,
            'text' => JsonTextNode::class,
            'opaque' => JsonOpaqueNode::class,
            default => null,
        };
    }
}
