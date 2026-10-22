<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\FileReconcileInputData;
use Splicewire\Beam\Ux\Data\FileReconcileResultData;
use Splicewire\Beam\Ux\Files\FileOperation;
use Splicewire\Beam\Ux\Files\ThemeReconciliation;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;

// Generated Particle shape; the comparison panel captures preconditions rather than a generic form.
#[ParticleOp(resource: 'beam-ux-release-files', name: 'take-file-draft', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: FileReconcileInputData::class,
    output: FileReconcileResultData::class, method: HttpMethod::Post, affordance: false)]
final class FileTakeDraftOp
{
    public static function handle(Model $model, Request $request, mixed $actor): FileReconcileResultData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var BeamUxReleaseMember $model */
        return FileOperation::run(fn () => app(ThemeReconciliation::class)->take($model, $actor, FileReconcileInputData::validateAndCreate($request->all())));
    }
}
