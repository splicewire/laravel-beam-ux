<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('New component')]
final class EntryComponentCreateInputData extends BeamData
{
    use \Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

    /** @param list<\Splicewire\Beam\Ux\JsonDoc\JsonNode> $body */
    public function __construct(
        #[Description('The display title of the reusable component to create.')]
        public string $title,
        #[\Spatie\LaravelData\Attributes\DataCollectionOf(\Splicewire\Beam\Ux\JsonDoc\JsonNode::class)]
        #[Description('The single canvas root to save as the reusable component body.')]
        public array $body,
    ) {}

    public static function validate(\Illuminate\Contracts\Support\Arrayable|array $payload): \Illuminate\Contracts\Support\Arrayable|array
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        CanvasBodyInputData::validate(array_intersect_key($values, ['body' => true]));

        return parent::validate($payload);
    }

    public static function validateAndCreate(\Illuminate\Contracts\Support\Arrayable|array $payload): static
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        CanvasBodyInputData::validateAndCreate(array_intersect_key($values, ['body' => true]));

        return parent::validateAndCreate($payload);
    }

    public static function rules(): array
    {
        return ['title' => ['required', 'string', 'max:160'], 'body' => ['present', 'array', 'list', 'size:1']];
    }
}
