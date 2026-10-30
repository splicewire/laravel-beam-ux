<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Record choice')]
final class CanvasRecordChoiceData extends BeamData
{
    public function __construct(public string $value, public string $label) {}
}
