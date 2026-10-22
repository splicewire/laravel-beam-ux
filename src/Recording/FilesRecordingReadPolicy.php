<?php

namespace Splicewire\Beam\Ux\Recording;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/** The scoped Files resource is not the global diagnostics resource. Its row scope checks all members. */
final class FilesRecordingReadPolicy implements ResourceReadPolicy
{
    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        return $actor === null ? Response::deny() : Gate::forUser($actor)->inspect('ux.author');
    }
}
