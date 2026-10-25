<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;

/** The typed blocks input; the legacy save-body DTO still accepts source/form dictionaries. */
#[TypeScript]
class CanvasBodyInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    /** @param list<JsonNode> $body */
    public function __construct(
        #[Description('The complete canvas document to replace. An empty list clears the blocks body.')]
        #[DataCollectionOf(JsonNode::class)]
        public array $body,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'body' => ['present', 'array', 'list'],
            'body.*' => ['array'],
            'body.*.kind' => ['required', 'string', 'in:block,text,opaque'],
        ];
    }
}
