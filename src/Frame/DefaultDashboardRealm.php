<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Throwable;

/** Select the personal first-run surface, never relax a tenant's membership gate. */
final class DefaultDashboardRealm
{
    public function resolve(?string $realm, Request $request): ?string
    {
        if ($realm !== null) {
            return $realm;
        }

        $default = config('beam.ux.frame_nav.default_realm');
        if ($default !== 'tenant' || $request->user() === null) {
            return is_string($default) ? $default : null;
        }

        try {
            // A foreign team or a failed resolver is NOT the absence of a team.
            return BeamAccounts::currentTeam() === null ? 'user' : 'tenant';
        } catch (Throwable) {
            return 'tenant';
        }
    }
}
