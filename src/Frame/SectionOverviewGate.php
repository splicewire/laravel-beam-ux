<?php

namespace Splicewire\Beam\Ux\Frame;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavGate;
use Rushing\DataNav\NavLink;
use Schemastud\Frame\Contracts\FrameNavContributor;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Realm\RealmRegistry;
use Throwable;

/**
 * Who may open a section's overview (ADR-0222 decision 9): a reader the realm's gate admits, the seat's own gates admit,
 * and at least one child of the section admits. The last clause is what makes "a reader who can open nothing in the
 * section gets no seat and no overview" one rule: without it the overview's leaf stays bound, and a bound seat is not
 * pruned as an empty one is.
 *
 * ## The seat's gates are the host's nav gate stages, in the host's context
 *
 * A seat's tokens mean whatever the host's stages say they mean, so this asks those stages, through the same
 * {@see NavGate::allows()} the rail is built with, in the context the rail's stages get: the bound contributor's own, read
 * through {@see ProvidesNavContext}. With no stage a token gates nothing, on the rail and here alike. A host that defines a
 * Gate ability under a token's name and registers no stage has not gated the seat. A defined `entitlement:` ability is the
 * one exception, read through {@see NavSectionProjector::entitled()} as the seat's lock is.
 *
 * ## When it refuses for want of a context
 *
 * It fails closed rather than guess at what a stage would have seen: no contributor is bound, the contributor does not
 * provide a context, providing one throws, or the context's user is not the reader being asked about. The last compares auth
 * identifiers, not objects, because a host may project the same person into a tenant as another model that shares the key.
 *
 * ## Why this cannot recurse
 *
 * Asking for the context reads no tree. It asks about the seat as a section header: `{key}.section`, carrying the seat's gate
 * meta and no route's seat gate, so nothing a stage reads off that node leads back to the overview's resource. It reads the
 * projected tree nowhere, because the projection admits this resource's leaf through this gate.
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

        if ($context === null) {
            return false;
        }

        $header = NavLink::make(title: $section->label, href: $section->href, icon: $section->icon, routeName: $section->key.'.section')
            ->withMeta($section->gateAfterLock() + [NavSectionProjector::CONTRIBUTED => true]);

        if (! $this->container->make(NavGate::class)->allows($header, $context)) {
            return false;
        }

        return $this->children->hasChildren($section->key, $section->realm, $context, $section->static);
    }

    /** The context the rail's stages get for this realm, if it is about this reader. */
    private function context(Authenticatable $user, string $realm): ?NavContext
    {
        if (! $this->container->bound(FrameNavContributor::class)) {
            return null;
        }

        try {
            $contributor = $this->container->make(FrameNavContributor::class);
            $context = $contributor instanceof ProvidesNavContext ? $contributor->navContextFor($realm) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $reader = $context?->user;

        return $reader !== null && (string) $reader->getAuthIdentifier() === (string) $user->getAuthIdentifier() ? $context : null;
    }
}
