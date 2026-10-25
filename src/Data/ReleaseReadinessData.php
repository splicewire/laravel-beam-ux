<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * What would stop a Release publishing, and what is worth knowing first (ADR-0232 d11). `blockers` lists every reason
 * Publish would refuse; Publish itself stops at the first. `notes` never block. A Release that is no longer open has
 * neither.
 */
#[TypeScript, Title('Release readiness')]
final class ReleaseReadinessData extends BeamData
{
    public function __construct(
        public bool $ready,
        #[DataCollectionOf(ReleaseReadinessItemData::class)] public array $blockers,
        #[DataCollectionOf(ReleaseReadinessItemData::class)] public array $notes,
    ) {}
}
