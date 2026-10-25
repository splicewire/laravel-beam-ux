<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryComponentCreateInputData;
use Splicewire\Beam\Ux\Data\EntryComponentCreateOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Native generator scaffold, narrowed to the existing addressed component authoring mechanisms. */
#[ParticleOp(resource: 'beam-ux-entry', name: 'create-component', kind: OperationKind::Write,
    ability: 'update', abilityModel: null, affordance: false,
    input: EntryComponentCreateInputData::class, output: EntryComponentCreateOutputData::class)]
final class EntryComponentCreateOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryComponentCreateOutputData
    {
        $service = app(\Splicewire\Beam\Ux\Components\ComponentAuthoring::class);
        $service->authorizeCreate($model, $actor);
        $input = EntryComponentCreateInputData::validateAndCreate($request->all());
        $entry = $service->create($model, $request, $actor, $input);

        return new EntryComponentCreateOutputData((string) $entry->getKey());
    }
}
