<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A page a change reaches (ADR-0232 d10), at the path it answers. A Record Page's path keeps its `{field}` piece: it
 * opens on a Record the viewer picks.
 */
#[TypeScript, Title('Reached page')]
final class ReachPageData extends BeamData
{
    public function __construct(
        public string $entryId,
        public ?string $title,
        public ?string $path,
        public bool $recordPage = false,
    ) {}
}
