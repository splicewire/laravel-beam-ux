<?php

namespace Splicewire\Beam\Ux\Diagnostics;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The read ability of beam-ux's Developer-zone diagnostics (ux-walkthrough UX-08c): mirror status and sitemap health.
 *
 * Both are backed by the entries model, whose read every member of a team passes, yet they show a host's file paths and
 * routing machinery. So each declares this ability as its `policy`, and laravel-beam's `ResourceReadGuard` holds every
 * read of them to it. It admits whoever may UPDATE entries, the tier a team's owner and admin already hold and a member
 * does not, so no new token exists and no role row changes. It also admits the operator entitlement
 * (`entitlement:os.operate`): a host that draws the diagnostics in its operator realm (the tower starter, IA-9) admits
 * whoever that realm admits, and a host that defines no such ability refuses it as before. A host's `Gate::before`
 * superuser (Root) passes as ever.
 */
class DiagnosticsAbility
{
    public const NAME = 'beam-ux.diagnostics.view';

    public static function define(): void
    {
        Gate::define(self::NAME, fn (?Authenticatable $user): bool => $user !== null
            && Gate::forUser($user)->any(['beam-ux-entry.update', 'entitlement:os.operate']));
    }
}
