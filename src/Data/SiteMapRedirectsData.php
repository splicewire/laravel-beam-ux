<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** A page's inbound Redirects: how many, the longest chain, and the worst end that fails a guest. */
#[TypeScript, Title('Site map redirects')]
final class SiteMapRedirectsData extends BeamData
{
    /** @param list<SiteMapInboundData> $inbound */
    public function __construct(
        public int $count = 0,
        #[Description('The most Redirects any old address follows to reach the page; above 1 is a chain, shown as information.')]
        public int $maxHops = 0,
        #[Description('The worst end among them that fails a guest (`loop`, `too-long`, `dead-end`, `gone`), or null.')]
        public ?string $attention = null,
        #[DataCollectionOf(SiteMapInboundData::class)]
        public array $inbound = [],
    ) {}
}
