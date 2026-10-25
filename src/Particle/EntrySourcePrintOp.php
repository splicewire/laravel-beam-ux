<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\Data\EntrySourceData;
use Splicewire\Beam\Ux\JsonDoc\SourceMode;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

#[ParticleOp(resource: 'beam-ux-entry', name: 'print-source', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, affordance: false,
    input: BeamUxEntryBodyInputData::class, output: EntrySourceData::class, method: HttpMethod::Post)]
class EntrySourcePrintOp
{
    public static function handle(Model $model, Request $request, mixed $actor): EntrySourceData
    {
        SourceMode::assertAvailable($model);
        $input = BeamUxEntryBodyInputData::validateAndCreate($request->all());

        /** @var BeamUxEntry $model */
        return new EntrySourceData(SourceMode::print($model, $input->body));
    }
}
