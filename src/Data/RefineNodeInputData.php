<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Contracts\Support\Arrayable;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Composition\NodeActions\NodeActionMode;

/** Bounded current Local Session body; a preview never changes the stored Working Head. */
#[\Schemastud\DataSchemas\Attributes\Title('Canvas node preview')]
class RefineNodeInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    /** @param list<JsonNode> $body @param list<string|int> $path */
    public function __construct(
        #[DataCollectionOf(JsonNode::class)]
        #[Description("The canvas's current, unsaved block body (its top-level node list). It gives the addressed node its parent context in place of the stored entry body; nothing is saved.")]
        public array $body,
        #[Description('Where the node sits in `body`: a list of 1 to 64 segments, node indexes joined by `children`, `slots` and a slot name, or `repeat` and `empty`. Always required; ignored when `nodeId` is given.')]
        public array $path,
        #[Description('`refine` proposes a rewrite of the node following `instruction`; `regenerate` proposes a fresh candidate for it.')]
        public NodeActionMode $mode,
        #[Description("The addressed node's id. When given, it addresses the node instead of `path` and must match exactly one node in `body`.")]
        public ?string $nodeId = null,
        #[Description('What to change, in plain words (at most 8000 bytes of UTF-8). Required when `mode` is `refine`.')]
        public ?string $instruction = null,
    ) {}

    public static function validate(Arrayable|array $payload): Arrayable|array
    {
        $values = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        EntryCanvasBindingsInputData::assertBounds($values);
        CanvasBodyInputData::validate(array_intersect_key($values, ['body' => true]));

        return parent::validate($payload);
    }

    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $values = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        EntryCanvasBindingsInputData::assertBounds($values);
        CanvasBodyInputData::validateAndCreate(array_intersect_key($values, ['body' => true]));

        return parent::validateAndCreate($payload);
    }

    public static function rules(): array
    {
        return [
            'body' => ['present', 'array', 'list'],
            'path' => ['required', 'array', 'list', 'min:1', 'max:64'],
            'path.*' => [function ($attribute, $value, $fail): void {
                if (! is_string($value) && ! is_int($value)) {
                    $fail(\Splicewire\Beam\Ux\Publish\EntryError::NodePreviewPathSegmentInvalid->message());
                }
            }],
            'nodeId' => ['nullable', 'string', 'max:200'],
            'instruction' => ['required_if:mode,refine', 'nullable', 'string', 'max:8000'],
        ];
    }
}
