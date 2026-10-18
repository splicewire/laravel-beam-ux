<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Release member')]
final class ReleaseMemberData extends BeamData
{
    public function __construct(
        public string $entryId,
        public ?string $versionId,
        public ?string $observedPin,
        public ?string $redirectTarget,
        public bool $stale,
    ) {}
}
