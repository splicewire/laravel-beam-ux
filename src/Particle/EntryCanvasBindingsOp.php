<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsOutputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Generated read scaffold, with addressed author admission before any body or Record read. */
#[ParticleOp(
    resource: 'beam-ux-entry', name: 'canvas-bindings', kind: OperationKind::Read,
    ability: 'update', abilityModel: null, affordance: false,
    input: EntryCanvasBindingsInputData::class, output: EntryCanvasBindingsOutputData::class,
    method: \Splicewire\Beam\Routing\HttpMethod::Post,
)]
class EntryCanvasBindingsOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryCanvasBindingsOutputData
    {
        $service = app(\Splicewire\Beam\Ux\Bindings\CanvasBindings::class);
        $service->authorize($model, $actor);
        $input = EntryCanvasBindingsInputData::validateAndCreate($request->all());

        return $service->resolve($model, $request, $input->toArray()['body'], $input->recordValue);
    }
}
