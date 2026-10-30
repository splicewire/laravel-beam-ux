<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Unsaved Local Session document only; no Collection, identity or saved settings override. */
#[TypeScript, Title('Canvas bindings request')]
final class EntryCanvasBindingsInputData extends BeamData
{
    public const MAX_BODY_BYTES = 262144;

    public const MAX_ROOT_REPEATS = 20;

    use \Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

    /** @param list<\Splicewire\Beam\Ux\JsonDoc\JsonNode> $body */
    public function __construct(
        #[\Spatie\LaravelData\Attributes\DataCollectionOf(\Splicewire\Beam\Ux\JsonDoc\JsonNode::class)]
        #[Description('The unsaved canvas document to inspect for live bindings; no saved entry body is changed.')]
        public array $body,
        #[\Spatie\TypeScriptTransformer\Attributes\Optional]
        #[Description('Optional record identifier used to resolve the canvas document in a record context.')]
        public ?string $recordValue = null,
    ) {}

    public static function validate(\Illuminate\Contracts\Support\Arrayable|array $payload): \Illuminate\Contracts\Support\Arrayable|array
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        self::assertBounds($values);
        CanvasBodyInputData::validate(array_intersect_key($values, ['body' => true]));

        return parent::validate($payload);
    }

    /** Validate the raw union first, as the existing typed save input does. */
    public static function validateAndCreate(\Illuminate\Contracts\Support\Arrayable|array $payload): static
    {
        $values = $payload instanceof \Illuminate\Contracts\Support\Arrayable ? $payload->toArray() : $payload;
        self::assertBounds($values);
        CanvasBodyInputData::validateAndCreate(array_intersect_key($values, ['body' => true]));

        return parent::validateAndCreate($payload);
    }

    /** Bound work before Data hydration, profile resolution or any Record read. */
    public static function assertBounds(array $values): void
    {
        $body = $values['body'] ?? [];
        $json = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || strlen($json) > self::MAX_BODY_BYTES) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'A live preview body may be at most 256 KiB.']);
        }
        $stack = is_array($body) ? array_values($body) : [];
        $count = 0;
        while ($stack !== []) {
            $node = array_pop($stack);
            if (! is_array($node)) {
                continue;
            }
            if (isset($node['repeat'])) {
                if (++$count > self::MAX_ROOT_REPEATS) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'A live preview may resolve at most 20 root Repeats.']);
                }
                // An empty subtree can resolve at this level; Record-scoped children are not root reads.
                $children = is_array($node['repeat']['empty'] ?? null) ? $node['repeat']['empty'] : [];
            } else {
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                foreach (is_array($node['slots'] ?? null) ? $node['slots'] : [] as $slot) {
                    if (is_array($slot)) {
                        array_push($children, ...array_values($slot));
                    }
                }
            }
            foreach ($children as $child) {
                $stack[] = $child;
            }
        }
    }

    public static function rules(): array
    {
        return ['body' => ['present', 'array', 'list'], 'recordValue' => ['nullable', 'string', 'max:200']];
    }
}
