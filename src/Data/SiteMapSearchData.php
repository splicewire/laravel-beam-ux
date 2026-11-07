<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Whether the sitemap lists a page, and why not: {@see \Splicewire\Beam\Ux\Sitemap\SitemapEligibility}'s verdict. */
#[TypeScript, Title('Site map search')]
final class SiteMapSearchData extends BeamData
{
    public function __construct(
        public bool $listed,
        #[Description('`listed`, `record-page` (listed, one URL per record), or why not: `not-routable`, `unpublished`, `not-public`, `unplaced`, `not-listable`, `hidden-ancestor`, `no-records`.')]
        public string $reason,
        #[Description('How many URLs the sitemap lists for the page: 1, a Record Page\'s record count, or 0.')]
        public int $urlCount,
    ) {}
}
