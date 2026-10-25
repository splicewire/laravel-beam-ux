<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** What one component, layout, template or theme member of a Release reaches (ADR-0232 d10). */
#[TypeScript, Title('Release member reach')]
final class ReleaseMemberReachData extends BeamData
{
    public function __construct(
        public string $entryId,
        public string $name,
        public string $kind,
        public bool $everyPage,
        public int $count,
        #[DataCollectionOf(ReachPageData::class)] public array $pages,
    ) {}
}
