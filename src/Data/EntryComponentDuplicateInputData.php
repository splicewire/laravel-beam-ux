<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Duplicate component')]
final class EntryComponentDuplicateInputData extends BeamData
{
    use \Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

    public function __construct() {}
}
