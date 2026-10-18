<?php

namespace Splicewire\Beam\Ux\Style;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
#[\Schemastud\DataSchemas\Attributes\Title('Block style')]
class BlockStyleData extends BeamData
{
    /** @param list<StyleLayerData> $layers */
    public function __construct(#[DataCollectionOf(StyleLayerData::class)] public array $layers) {}
}
