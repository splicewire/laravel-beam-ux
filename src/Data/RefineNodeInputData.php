<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Contracts\Support\Arrayable;
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
        #[DataCollectionOf(JsonNode::class)] public array $body,
        public array $path,
        public NodeActionMode $mode,
        public ?string $nodeId = null,
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
