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
            throw ValidationException::withMessages(['body' => 'save-blocks requires a blocks-kind entry.']);
        }
        try {
            $input = CanvasBodyInputData::validateAndCreate($request->all());
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            // As in CanvasBodyGuard, malformed morph arms are client errors, not hydration 500s.
            throw ValidationException::withMessages(['body' => 'This document has a node the canvas editor cannot read.']);
        }

        return parent::persistBody($model, $input->toArray()['body']);
    }
}
