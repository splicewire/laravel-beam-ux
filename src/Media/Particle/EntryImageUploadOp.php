<?php

namespace Splicewire\Beam\Ux\Media\Particle;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Media\Data\EntryImageData;
use Splicewire\Beam\Ux\Media\Data\EntryImageUploadData;
use Splicewire\Beam\Ux\Media\EntryMedia;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Generated operation scaffold; registered only on hosts binding beam-media. Parent update is checked in EntryMedia. */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'upload-image',
    kind: OperationKind::Write,
    ability: 'ux.author',
    abilityModel: false,
    affordance: false,
    input: EntryImageUploadData::class,
    output: EntryImageData::class,
)]
class EntryImageUploadOp
{
    public static function handle(BeamUxEntry $model, Request $request, mixed $actor): EntryImageData
    {
        $input = EntryImageUploadData::validateAndCreate($request->all());

        return app(EntryMedia::class)->upload($model, $input->file, $actor);
    }
}
