<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryComponentDuplicateInputData;
use Splicewire\Beam\Ux\Data\EntryComponentDuplicateOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Native generator scaffold, narrowed to the existing addressed component authoring mechanisms. */
#[ParticleOp(resource: 'beam-ux-entry', name: 'duplicate-component', kind: OperationKind::Write,
    ability: 'update', abilityModel: null, affordance: false,
    input: EntryComponentDuplicateInputData::class, output: EntryComponentDuplicateOutputData::class)]
final class EntryComponentDuplicateOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryComponentDuplicateOutputData
    {
        $entry = app(\Splicewire\Beam\Ux\Components\ComponentAuthoring::class)->duplicate($model, $actor);

        return new EntryComponentDuplicateOutputData((string) $entry->getKey());
    }
}
