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
        // A typed reference, not a literal: a host's generator emits only the types it reaches along references.
        #[\Spatie\TypeScriptTransformer\Attributes\TypeScriptType('array<string, RepeatRecordsData>')]
        public array $bindings = [],
    ) {}
}
