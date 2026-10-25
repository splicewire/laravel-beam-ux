<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class ComponentArtifactData extends BeamData
{
    /** @param array<string, mixed> $bindings */
    public function __construct(
        public string $name,
        public ?string $url,
        public ?string $version,
        #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType('Record<string, RepeatRecordsData>')]
        public array $bindings = [],
    ) {}
}
