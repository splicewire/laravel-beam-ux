<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class EntryComponentCapabilitiesInputData extends BeamData
{
    use \Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

    public function __construct() {}
}
