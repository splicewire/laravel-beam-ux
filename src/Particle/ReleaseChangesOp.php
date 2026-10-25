<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Ux\Data\ReleaseChangesData;
use Splicewire\Beam\Ux\Release\ReleaseChanges;

/** The pages the Release changes and what its members reach (ADR-0232 d10), for the Release panel. */
#[ParticleOp(resource: 'beam-ux-release', name: 'changes', kind: OperationKind::Read,
    ability: 'ux.author', abilityModel: false, input: false,
    output: ReleaseChangesData::class, method: HttpMethod::Get, affordance: false)]
final class ReleaseChangesOp
{
    public static function handle(Model $model, Request $request, mixed $actor): ReleaseChangesData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var \Splicewire\Beam\Ux\Models\BeamUxRelease $model */
        return app(ReleaseChanges::class)->of($model);
    }
}
