<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The declared `output:` of `beam-ux-redirect`'s `traces` read: every Redirect of the site, walked once as a guest. */
#[TypeScript, Title('Redirect traces')]
final class RedirectTracesData extends BeamData
{
    /** @param list<RedirectTraceData> $traces */
    public function __construct(
        #[DataCollectionOf(RedirectTraceData::class)]
        public array $traces = [],
    ) {}
}
