<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The pages a Release changes (ADR-0232 d10): its own pages first, then what each component, layout, template or theme
 * member reaches. Paths are the ones the Release's Preview answers.
 */
#[TypeScript, Title('Release changes')]
final class ReleaseChangesData extends BeamData
{
    public function __construct(
        #[DataCollectionOf(ReachPageData::class)] public array $pages,
        #[DataCollectionOf(ReleaseMemberReachData::class)] public array $reach,
    ) {}
}
