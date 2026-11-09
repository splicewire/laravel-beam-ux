<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Who may open a section's overview (ADR-0222 decision 9, owner ruling 2026-11-09): a reader the realm's dashboard ability
 * admits, and the seat's `entitlement:` lock admits. Nothing more. It is the realm dashboard's own two-gate shape, this
 * resource's `policy:` plus one check per card, and the second gate lives in {@see \Splicewire\Beam\Ux\Particle\Backing\DashboardBacking}.
 *
 * ## What it does not decide, on purpose
 *
 * It does not rebuild the rail. Whether the seat shows, and which cards and tiles the page draws, is decided by the real
 * projection: the bound contributor's tree, gated by the host's own stages in the host's own context. A seat the rail hides is
 * presentation. A reader who opens the overview's URL anyway gets the cards their own rail admits in that section, which is
 * none when it admits nothing, exactly as typing the URL of a resource whose seat is hidden shows only what that resource admits.
 *
 * The entitlement lock is read through {@see NavSectionProjector::entitled()}, the same reading that decides whether the seat
 * shows locked, so a reader the lock refuses gets neither a landing nor the page.
 */
class SectionOverviewGate
{
    public function __construct(
        private Gate $gate,
        private RealmRegistry $realms,
        private NavSectionProjector $seats,
    ) {}

    public function allows(?Authenticatable $user, NavSection $section): bool
    {
        if ($user === null || ! $this->gate->forUser($user)->allows(RealmDashboard::abilityFor($section->realm, $this->realms))) {
            return false;
        }

        return ! $section->isEntitlementGated() || $this->seats->entitled($section, $user);
    }
}
