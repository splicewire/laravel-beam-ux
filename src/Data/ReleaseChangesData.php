<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The pages a Release changes (ADR-0232 d10): its own pages first, then what each component, layout, template or theme
 * member reaches. Paths are the ones the Release's Preview answers.
 *
 * `config` lists what each config member changes, key by key (config-sections ticket 11). It is a SEPARATE list: a
 * config section reaches no page, so it never joins `pages` or `reach` (the page-reach map the Preview reads).
 */
#[TypeScript, Title('Release changes')]
final class ReleaseChangesData extends BeamData
{
    public function __construct(
        #[DataCollectionOf(ReachPageData::class)] public array $pages,
        #[DataCollectionOf(ReleaseMemberReachData::class)] public array $reach,
        #[DataCollectionOf(ReleaseConfigChangeData::class)] public array $config,
    ) {}
}
