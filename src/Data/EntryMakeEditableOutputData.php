<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[\Schemastud\DataSchemas\Attributes\Title('Editable page')]
#[TypeScript]
final class EntryMakeEditableOutputData extends BeamData
{
    public function __construct(public string $id, public ?string $compileError) {}
}
