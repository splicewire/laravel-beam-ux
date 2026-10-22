<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            throw ValidationException::withMessages(['releaseId' => 'The selected Release must contain this addressed file.']);
        }
        $release = BeamUxRelease::on($model->getConnectionName())->findOrFail($input->releaseId);
        try {
            app(ReleaseRecording::class)->retry($release, $actor);
        } catch (RecordingFailure $failure) {
            throw ValidationException::withMessages(['releaseId' => $failure->getMessage()]);
        }

        $result = ReleaseRecordingData::project($release->fresh(), $actor);
        if ($result->status !== 'recorded') {
            throw ValidationException::withMessages(['releaseId' => $result->message ?? 'The local recording did not complete.']);
        }

        return $result;
    }
}
