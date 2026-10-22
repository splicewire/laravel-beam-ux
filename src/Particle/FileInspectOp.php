<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\FileInspectionData;
use Splicewire\Beam\Ux\Files\FileOperation;
use Splicewire\Beam\Ux\Files\ThemeReconciliation;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;

// Generated Particle shape; the comparison panel captures preconditions rather than a generic form.
#[ParticleOp(resource: 'beam-ux-release-files', name: 'inspect-file', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, input: false,
    output: FileInspectionData::class, method: HttpMethod::Get, affordance: false)]
final class FileInspectOp
{
    public static function handle(Model $model, Request $request, mixed $actor): FileInspectionData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var BeamUxReleaseMember $model */
        return FileOperation::run(fn () => app(ThemeReconciliation::class)->inspect($model, $actor));
    }
}
