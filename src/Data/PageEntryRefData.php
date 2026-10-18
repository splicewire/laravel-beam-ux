<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Page entry reference')]
final class PageEntryRefData extends BeamData
{
    /** @param array<string, mixed>|null $artifact */
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $format,
        public ?array $artifact,
    ) {}
}
