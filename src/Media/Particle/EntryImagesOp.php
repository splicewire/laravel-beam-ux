<?php

namespace Splicewire\Beam\Ux\Media\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Media\Data\EntryImageLibraryData;
use Splicewire\Beam\Ux\Media\EntryMedia;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Generated operation scaffold; registered only on hosts binding beam-media. Parent update is checked in EntryMedia. */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'media',
    kind: OperationKind::Read,
    ability: 'ux.author',
    abilityModel: false,
    affordance: false,
    method: \Splicewire\Beam\Routing\HttpMethod::Get,
    input: false,
    output: EntryImageLibraryData::class,
)]
class EntryImagesOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryImageLibraryData
    {
        return app(EntryMedia::class)->library($model, $actor);
    }
}
