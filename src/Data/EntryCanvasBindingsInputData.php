<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Unsaved Local Session document only; no Collection, identity or saved settings override. */
#[TypeScript]
final class EntryCanvasBindingsInputData extends BeamData
{
    use \Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

    /** @param list<\Splicewire\Beam\Ux\JsonDoc\JsonNode> $body */
    public function __construct(
        #[\Spatie\LaravelData\Attributes\DataCollectionOf(\Splicewire\Beam\Ux\JsonDoc\JsonNode::class)]
        public array $body,
        #[\Spatie\TypeScriptTransformer\Attributes\Optional]
        public ?string $recordValue = null,
    ) {}

    public static function validate(\Illuminate\Contracts\Support\Arrayable|array $payload): \Illuminate\Contracts\Support\Arrayable|array
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        CanvasBodyInputData::validate(array_intersect_key($values, ['body' => true]));

        return parent::validate($payload);
    }

    /** Validate the raw union first, as the existing typed save input does. */
    public static function validateAndCreate(\Illuminate\Contracts\Support\Arrayable|array $payload): static
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        CanvasBodyInputData::validateAndCreate(array_intersect_key($values, ['body' => true]));

        return parent::validateAndCreate($payload);
    }

    public static function rules(): array
    {
        return ['body' => ['present', 'array', 'list'], 'recordValue' => ['nullable', 'string', 'max:200']];
    }
}
