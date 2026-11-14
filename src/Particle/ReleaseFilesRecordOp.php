<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\ReleaseRecordingData;
use Splicewire\Beam\Ux\Data\ReleaseRecordInputData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Recording\RecordingFailure;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;

#[ParticleOp(resource: 'beam-ux-release-files', name: 'commit-now', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: ReleaseRecordInputData::class,
    output: ReleaseRecordingData::class, affordance: new ActionAffordance(label: 'Commit now'))]
final class ReleaseFilesRecordOp
{
    public static function handle(Model $model, Request $request, mixed $actor): ReleaseRecordingData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }
        $input = ReleaseRecordInputData::validateAndCreate($request->all());
        if ($input->releaseId !== $model->release_id) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('releaseId', \Splicewire\Beam\Ux\Recording\RecordingError::ReleaseMustContainFile);
        }
        $release = BeamUxRelease::on($model->getConnectionName())->findOrFail($input->releaseId);
        try {
            app(ReleaseRecording::class)->retry($release, $actor);
        } catch (RecordingFailure $failure) {
            // The response emit (coord-lead ruling (a)): the failure's case, localized, with its code; its reason and
            // diagnostic stay raw. A failure built from a string (none today) keeps its text only as the diagnostic.
            throw $failure->error === null
                ? \Splicewire\Beam\Ux\Localization\DiagnosedValidationException::diagnosed('releaseId', \Splicewire\Beam\Ux\Recording\RecordingError::DidNotComplete, $failure)
                : \Splicewire\Beam\Localization\CodedValidationException::for('releaseId', $failure->error, $failure->params);
        }

        $result = ReleaseRecordingData::project($release->fresh(), $actor);
        if ($result->status !== 'recorded') {
            // The status line is already keyed (ReleaseRecordingData); it travels with its code.
            throw $result->message === null
                ? \Splicewire\Beam\Localization\CodedValidationException::for('releaseId', \Splicewire\Beam\Ux\Recording\RecordingError::DidNotComplete)
                : \Splicewire\Beam\Localization\CodedValidationException::for('releaseId', \Splicewire\Beam\Ux\Recording\RecordingError::NotRecorded, ['detail' => $result->message]);
        }

        return $result;
    }
}
