<?php

namespace Splicewire\Beam\Ux\Particle;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Ux\Data\PreviewLinkData;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\TenantPreviewOpenCapability;

/** Mint a one-use, short-lived signed Preview opener for a tenant Release. */
#[ParticleOp(resource: 'beam-ux-release', name: 'preview-open', kind: OperationKind::Write,
    ability: 'ux.author', abilityModel: false, input: false, output: PreviewLinkData::class)]
final class ReleasePreviewOpenOp
{
    public static function handle(Model $model, Request $request, mixed $actor): PreviewLinkData
    {
        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException;
        }

        /** @var BeamUxRelease $model */
        return new PreviewLinkData(app(TenantPreviewOpenCapability::class)->mint($model, $actor, $request));
    }
}
