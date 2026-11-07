<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Who may open a section's overview (ADR-0222 decision 9): a reader the realm's gate admits, the seat's own gates admit,
 * and at least one child of the section admits. The last clause is what makes "a reader who can open nothing in the
 * section gets no seat and no overview" one rule: without it the overview's leaf stays bound, and a bound seat is not
 * pruned as an empty one is.
 *
 * It asks the rail's questions directly rather than reading the projected tree, because the projection admits this very
 * resource's leaf through this gate.
 */
class SectionOverviewGate
{
    public function __construct(
        private Gate $gate,
        private RealmRegistry $realms,
        private NavSectionProjector $seats,
        private FrameResourcesInvocable $children,
    ) {}

    public function allows(?Authenticatable $user, NavSection $section): bool
    {
        if ($user === null) {
            return false;
        }

        $gate = $this->gate->forUser($user);

        if (! $gate->allows(RealmDashboard::abilityFor($section->realm, $this->realms))) {
            return false;
        }

        if ($section->isEntitlementGated() && ! $this->seats->entitled($section, $user)) {
            return false;
        }

        // An undefined permission token denies at Gate and is ignored by a host with no permission stage, so it is read
        // the way the nav reads it there: not configured is not denied.
        if ($section->permission !== null && $this->gate->has($section->permission) && ! $gate->allows($section->permission)) {
            return false;
        }

        return $this->children->hasChildren($section->key, $section->realm, $user, $section->static);
    }
}
