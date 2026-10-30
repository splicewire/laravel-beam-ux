<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Preview request')]
final class EntryPreviewInputData extends BeamData
{
    public function __construct(
        #[Description('The saved entry version UUID whose body is opened in the preview.')]
        public string $versionId,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['versionId' => ['required', 'string', 'uuid']];
    }
}
