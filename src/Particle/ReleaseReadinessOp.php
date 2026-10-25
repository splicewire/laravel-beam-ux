<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\ReleaseReadinessData;
use Splicewire\Beam\Ux\Release\ReleaseReadiness;

/** What would stop the Release publishing (ADR-0232 d11), for the Release panel; the Preview bar reads the same report. */
#[ParticleOp(resource: 'beam-ux-release', name: 'readiness', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, input: false,
    output: ReleaseReadinessData::class, method: HttpMethod::Get, affordance: false)]
final class ReleaseReadinessOp
{
    public static function handle(Model $model, Request $request, mixed $actor): ReleaseReadinessData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var \Splicewire\Beam\Ux\Models\BeamUxRelease $model */
        return app(ReleaseReadiness::class)->report($model);
    }
}
