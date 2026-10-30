<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript, Title('Record release')]
final class ReleaseRecordInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Description('Confirm the Release ID shown above. Commit now records every member listed in Whole Release, not only this row.')]
        #[Title('Release ID')]
        public string $releaseId,
    ) {}

    public static function rules(): array
    {
        return ['releaseId' => ['required', 'uuid']];
    }
}
