<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavGate;
use Rushing\DataNav\NavLink;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Who may open a section's overview (ADR-0222 decision 9): a reader the realm's gate admits, the seat's own gates admit,
 * and at least one child of the section admits. The last clause is what makes "a reader who can open nothing in the
 * section gets no seat and no overview" one rule: without it the overview's leaf stays bound, and a bound seat is not
 * pruned as an empty one is.
 *
 * ## The seat's gates are the host's nav gate stages
 *
 * A seat's tokens mean whatever the host's stages say they mean, so this asks those stages, through the same
 * {@see NavGate::allows()} the rail is built with. With no stage a token gates nothing, on the rail and here alike. A host
 * that defines a Gate ability under a token's name and registers no stage has not gated the seat. A defined
 * `entitlement:` ability is the one exception, read through {@see NavSectionProjector::entitled()} as the seat's lock is.
 *
 * ## Why this cannot recurse
 *
 * It asks about the seat as a section header: `{key}.section`, carrying the seat's gate meta and no route's seat gate. Nothing
 * a stage reads off that node leads back to the overview's resource. It reads the projected tree nowhere, because the
 * projection admits this resource's leaf through this gate.
 *
 * ## What it cannot see
 *
 * A host that binds its own {@see \Schemastud\Frame\Contracts\FrameNavContributor} can build a richer context for its
 * stages, and that port offers no way to ask for it. So this context is the actor, the request and the realm, plus the
 * attributes of a build already open. A stage that needs more than that sees less here than on the rail.
 */
class SectionOverviewGate
{
    public function __construct(
        private Gate $gate,
        private RealmRegistry $realms,
        private NavSectionProjector $seats,
        private FrameResourcesInvocable $children,
        private Container $container,
    ) {}

    public function allows(?Authenticatable $user, NavSection $section): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $this->gate->forUser($user)->allows(RealmDashboard::abilityFor($section->realm, $this->realms))) {
            return false;
        }

        if ($section->isEntitlementGated() && ! $this->seats->entitled($section, $user)) {
            return false;
        }

        $context = $this->context($user, $section->realm);
        $header = NavLink::make(title: $section->label, href: $section->href, icon: $section->icon, routeName: $section->key.'.section')
            ->withMeta($section->gateAfterLock() + [NavSectionProjector::CONTRIBUTED => true]);

        if (! $this->container->make(NavGate::class)->allows($header, $context)) {
            return false;
        }

        return $this->children->hasChildren($section->key, $section->realm, $context, $section->static);
    }

    private function context(Authenticatable $user, string $realm): NavContext
    {
        $building = $this->container->bound(NavContext::class) ? $this->container->make(NavContext::class) : null;

        return new NavContext(
            user: $user,
            request: $building?->request ?? ($this->container->bound('request') ? $this->container->make('request') : null),
            attributes: ['realm' => $realm] + ($building?->attributes ?? []),
        );
    }
}
