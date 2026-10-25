<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
final class EntryComponentDuplicateOutputData extends BeamData
{
    public function __construct(public string $id) {}
}
