<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[\Schemastud\DataSchemas\Attributes\Title('Make page editable')]
#[TypeScript]
final class EntryMakeEditableInputData extends BeamData
{
    /** @param array<string, mixed> $body The page's current default JsonDoc. */
    public function __construct(public string $slug, public array $body) {}

    public static function rules(): array
    {
        return ['slug' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/'], 'body' => ['present', 'array']];
    }
}
