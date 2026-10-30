<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Duplicated component')]
final class EntryComponentDuplicateOutputData extends BeamData
{
    public function __construct(public string $id) {}
}
