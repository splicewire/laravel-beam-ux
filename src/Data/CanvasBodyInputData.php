<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

    /** Validate the outer collection before Spatie traverses abstract/morph items. */
    public static function validate(Arrayable|array $payload): Arrayable|array
    {
        self::assertDocument($payload);

        return parent::validate($payload);
    }

    public static function validateAndCreate(Arrayable|array $payload): static
    {
        self::assertDocument($payload);

        return parent::validateAndCreate($payload);
    }

    private static function assertDocument(Arrayable|array $payload): void
    {
        $values = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        Validator::make($values, self::rules())->validate();
        try {
            foreach ($values['body'] as $node) {
                JsonNode::from($node);
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            // Use the declared union's constructors, including nested arms; no parallel node schema.
            throw ValidationException::withMessages(['body' => 'This document has a node the canvas editor cannot read.']);
        }
    }

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
