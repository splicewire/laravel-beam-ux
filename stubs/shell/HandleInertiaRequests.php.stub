<?php

namespace App\Http\Middleware;

use Inertia\Middleware;
use Splicewire\Beam\Ux\Http\Concerns\SharesBeamShell;

/**
 * The host's Inertia middleware. The shell's shared props (brand, realms, auth, nav, accountNav, accountShell, can,
 * realmManifest, theme) are packaged in {@see SharesBeamShell}; this host tailors none of them. A host that does
 * overrides that one method here, never a copy of the share.
 */
class HandleInertiaRequests extends Middleware
{
    use SharesBeamShell;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';
}
