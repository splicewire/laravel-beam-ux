<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One old address that leads (or fails to lead) a guest to a page; a {@see RedirectTraceData} seen from the page. */
#[TypeScript, Title('Old address')]
final class SiteMapInboundData extends BeamData
{
    public function __construct(
        public string $id,
        public string $path,
        public string $match,
        public string $end,
        public int $hops,
        public bool $attention,
        public bool $permanent,
        public string $origin,
    ) {}
}
