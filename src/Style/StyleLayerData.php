<?php

namespace Splicewire\Beam\Ux\Style;

use Spatie\TypeScriptTransformer\Attributes\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
#[\Schemastud\DataSchemas\Attributes\Title('Style layer')]
class StyleLayerData extends BeamData
{
    /** @param array<string,string> $declarations */
    public function __construct(
        public array $declarations,
        #[Optional]
        public ?string $breakpoint = null,
        #[Optional]
        public ?string $state = null,
    ) {}
}
