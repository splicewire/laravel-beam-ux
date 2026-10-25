<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryComponentCapabilitiesInputData;
use Splicewire\Beam\Ux\Data\EntryComponentCapabilitiesOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Native generator scaffold, narrowed to the existing addressed component authoring mechanisms. */
#[ParticleOp(resource: 'beam-ux-entry', name: 'component-capabilities', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, affordance: false,
    input: EntryComponentCapabilitiesInputData::class, output: EntryComponentCapabilitiesOutputData::class,
    method: \Splicewire\Beam\Routing\HttpMethod::Get)]
final class EntryComponentCapabilitiesOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryComponentCapabilitiesOutputData
    {
        return app(\Splicewire\Beam\Ux\Components\ComponentAuthoring::class)->capabilities($model, $actor);
    }
}
