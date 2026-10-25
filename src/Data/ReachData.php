<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The live pages an entry reaches, and how many (ADR-0232 d10; ADR-0231 d16). A theme reaches `everyPage`. */
#[TypeScript, Title('Reach')]
final class ReachData extends BeamData
{
    public function __construct(
        public bool $everyPage,
        public int $count,
        #[DataCollectionOf(ReachPageData::class)] public array $pages,
    ) {}
}
