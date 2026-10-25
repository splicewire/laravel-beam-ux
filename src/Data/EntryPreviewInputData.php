<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class EntryPreviewInputData extends BeamData
{
    public function __construct(public string $versionId) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['versionId' => ['required', 'string', 'uuid']];
    }
}
