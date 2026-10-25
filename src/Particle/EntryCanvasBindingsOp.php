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

        // The guards consume the validated wire document. Named Slot map values are
        // hydrated by JsonBlockNode, but are not a typed Data collection; toArray()
        // leaves their nested nodes as objects instead of the required wire arrays.
        return $service->resolve($model, $request, $request->input('body'), $input->recordValue);
    }
}
