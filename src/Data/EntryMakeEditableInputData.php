<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[\Schemastud\DataSchemas\Attributes\Title('Make page editable')]
#[TypeScript]
final class EntryMakeEditableInputData extends BeamData
{
    /** @param array<string, mixed> $body The page's current default JsonDoc. */
    public function __construct(
        #[Description('The kit page\'s slug to create the editable entry for. Refused if the page already has one.')]
        public string $slug,
        #[Description('The page\'s current default JsonDoc, which becomes the new entry\'s first body.')]
        public array $body,
    ) {}

    public static function rules(): array
    {
        return ['slug' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/'], 'body' => ['present', 'array']];
    }
}
