<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\CanvasBodyInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Blocks-only typed write. Scaffolded with the native particle-op generator; legacy save-body remains. */
#[ParticleOp(
    resource: 'beam-ux-entry',
    name: 'save-blocks',
    kind: OperationKind::Write,
    ability: 'ux.author',
    abilityModel: false,
    affordance: false,
    input: CanvasBodyInputData::class,
    output: BeamUxEntryBodyData::class,
)]
class EntryBlocksSaveOp extends EntryBodySaveOp
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        /** @var BeamUxEntry $model */
        if ($model->bodyKind() !== BodyKind::Blocks) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('body', \Splicewire\Beam\Ux\Publish\EntryError::BlocksKindRequired);
        }
        try {
            $input = CanvasBodyInputData::validateAndCreate($request->all());
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            // As in CanvasBodyGuard, malformed morph arms are client errors, not hydration 500s.
            throw \Splicewire\Beam\Localization\CodedValidationException::for('body', \Splicewire\Beam\Ux\JsonDoc\SourceError::CanvasNodeUnreadable);
        }

        return parent::persistBody($model, $input->toArray()['body']);
    }
}
