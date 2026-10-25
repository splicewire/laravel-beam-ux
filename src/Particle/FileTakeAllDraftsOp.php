<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\FileBatchReconcileInputData;
use Splicewire\Beam\Ux\Data\FileBatchReconcileResultData;
use Splicewire\Beam\Ux\Files\FileOperation;
use Splicewire\Beam\Ux\Files\ThemeReconciliation;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;

#[ParticleOp(resource: 'beam-ux-release-files', name: 'take-all-drafts', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: FileBatchReconcileInputData::class,
    output: FileBatchReconcileResultData::class, method: HttpMethod::Post, affordance: false)]
final class FileTakeAllDraftsOp
{
    public static function handle(Model $model, Request $request, mixed $actor): FileBatchReconcileResultData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var BeamUxReleaseMember $model */
        return FileOperation::run(fn () => app(ThemeReconciliation::class)->takeAll($model, $actor,
            FileBatchReconcileInputData::validateAndCreate($request->all())));
    }
}
