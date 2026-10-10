<?php

namespace Splicewire\Beam\Ux\Diagnostics;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * The diagnostics projection's primary read decision, independent of its BeamUxEntry query backing.
 *
 * Mirror status and sitemap health query entries, but they are not the ordinary entry list. Reusing
 * BeamUxEntry's viewAny policy would make DiagnosticsAbility's operator arm ineffective: an operator
 * can inspect site-global machinery without receiving access to every authored entry.
 */
class DiagnosticsReadPolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        return Gate::forUser($actor)->inspect(DiagnosticsAbility::NAME);
    }
}
