<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Preview request')]
final class EntryPreviewInputData extends BeamData
{
    public function __construct(public string $versionId) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['versionId' => ['required', 'string', 'uuid']];
    }
}
