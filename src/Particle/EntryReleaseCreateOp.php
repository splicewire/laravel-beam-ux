<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\ReleaseCreateData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Release\ReleaseService;

#[ParticleOp(resource: 'beam-ux-entry', name: 'create-release', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: ReleaseCreateData::class, output: ReleaseData::class)]
final class EntryReleaseCreateOp
{
    public static function handle(Model $model, Request $request, mixed $actor): ReleaseData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }
        $input = ReleaseCreateData::validateAndCreate($request->all());

        return ReleaseData::project(app(ReleaseService::class)->create($model, $input->title, $actor));
    }
}
