<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Bindings\CanvasBindings;
use Splicewire\Beam\Ux\Data\RefineNodeInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\NodeActions\CanvasNodePreview;
use Splicewire\Composition\NodeActions\NodeActionDecisionData;

/** Generated read scaffold: POST carries a dirty document; kept means offered, with no Recorder. */
#[ParticleOp(
    resource: 'beam-ux-entry', name: 'refine-node', kind: OperationKind::Read,
    summary: 'Preview a refined or regenerated canvas node',
    description: 'Offers an addressed candidate through the host-bound spine producer and the ordinary canvas hard save guard. The current body, not stored content, provides parent context. Accept is a separate client-session change; no entry, draft, revision or version is written.',
    ability: 'update', abilityModel: null, affordance: false,
    input: RefineNodeInputData::class, output: NodeActionDecisionData::class,
    method: HttpMethod::Post,
)]
class EntryRefineNodeOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): NodeActionDecisionData
    {
        app(CanvasBindings::class)->authorize($model, $actor);
        $input = RefineNodeInputData::validateAndCreate($request->all());

        return app(CanvasNodePreview::class)->preview($model, $actor, $request->input('body'),
            $input->path, $input->nodeId, $input->mode, $input->instruction);
    }
}
