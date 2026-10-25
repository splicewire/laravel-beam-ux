<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;

/** Managing Redirects takes the rights publishing takes on the site (ADR-0236 d14; EP-30 ruling 2). */
final class RedirectPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('ux.author');
    }

    public function view(?Authenticatable $user, BeamUxRedirect $redirect): bool
    {
        return Gate::forUser($user)->allows('ux.author');
    }

    /** A new Redirect's target is known only in its input, so the service checks the page ability before the write. */
    public function create(?Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('ux.author');
    }

    public function update(?Authenticatable $user, BeamUxRedirect $redirect): bool
    {
        return app(RedirectService::class)->mayManage($user, $redirect);
    }

    public function delete(?Authenticatable $user, BeamUxRedirect $redirect): bool
    {
        return app(RedirectService::class)->mayManage($user, $redirect);
    }
}
