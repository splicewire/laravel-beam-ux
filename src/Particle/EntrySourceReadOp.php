<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\Data\EntrySourceReadInputData;
use Splicewire\Beam\Ux\JsonDoc\SourceMode;
use Splicewire\Beam\Ux\JsonDoc\SourceToBlocks;

#[ParticleOp(resource: 'beam-ux-entry', name: 'read-source', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, affordance: false,
    input: EntrySourceReadInputData::class, output: BeamUxEntryBodyInputData::class, method: HttpMethod::Post)]
class EntrySourceReadOp
{
    public static function handle(Model $model, Request $request, mixed $actor): BeamUxEntryBodyInputData
    {
        SourceMode::assertAvailable($model);
        $input = EntrySourceReadInputData::validateAndCreate($request->all());

        return new BeamUxEntryBodyInputData(app(SourceToBlocks::class)->convert($input->source, $input->body));
    }
}
