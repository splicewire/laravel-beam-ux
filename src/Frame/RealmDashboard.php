<?php

namespace Splicewire\Beam\Ux\Frame;

use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Concerns\WiresRealmDashboards;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;

/**
 * The naming and gating vocabulary of the one read-only dashboard resource beam-ux registers per realm
 * (realm-dashboards ticket 04) — written once so the registration ({@see WiresRealmDashboards}), the
 * backing ({@see DashboardBacking}), the nav leaf ({@see NavSectionProjector}) and the router leaf
 * ({@see RouteContextProjector}) cannot spell the key, the route name or the path differently.
 *
 * ## Why the key carries the realm
 *
 * Frame's resource socket is mounted ONCE and realm-blind (only the manifest is mounted per realm), so a
 * single `dashboard` key could not know which realm's cards to stream. `{realm}-dashboard` is a distinct
 * resource per realm, a member of that realm only; the realm is derived from membership, never from the
 * route.
 *
 * ## Why the PATH is not the key
 *
 * The router leaf follows the declared route name's stem, which would put the operator dashboard at
 * `/operator/operator-dashboard`. The realm base already names the realm, so the leaf mounts at
 * `/{realmBase}/dashboard` — {@see RouteContextProjector} reads {@see PATH} for exactly this key.
 */
final class RealmDashboard
{
    /** The in-realm path segment the dashboard's list leaf mounts at, under the realm's `routeBase`. */
    public const PATH = 'dashboard';

    /**
     * The Gate ability a dashboard declares in a realm that gates NOTHING — a non-central realm with no
     * `beam.core.realm_gates.{realm}.entitlement`.
     *
     * Such a realm admits every authenticated principal to every resource in it, so the honest ceiling for
     * its dashboard is the same: any authenticated actor. That is the posture an UNDECLARED `policy:` has —
     * but written down as a declared ability, so `ModelLessReadGateAudit` reads a decision rather than an
     * omission. beam-ux defines the ability itself ({@see WiresRealmDashboards}) as "a principal is
     * present"; a host that wants a narrower door redefines it from its own provider, which boots later
     * and wins. The dashboard's SECOND gate is per row: {@see DashboardBacking} keeps only the resources
     * {@see \Splicewire\Beam\Authorization\ResourceVisibility::listable()} admits the actor to.
     */
    public const OPEN_ABILITY = 'realm-dashboard.view';

    public static function keyFor(string $realm): string
    {
        return $realm.'-dashboard';
    }

    public static function routeNameFor(string $realm): string
    {
        return self::keyFor($realm).'.index';
    }

    public static function isKey(string $key, string $realm): bool
    {
        return $key === self::keyFor($realm);
    }

    /**
     * The `policy:` a realm's dashboard declares — the SAME ability string
     * {@see RealmEntitlementResourceGate} gates the realm's resources on, so the dashboard opens no door
     * the realm does not: `entitlement:{declared}` for a realm with a declared gate,
     * `entitlement:os.operate` for a central realm with none, and {@see OPEN_ABILITY} otherwise.
     *
     * Mirrors the gate's protected `abilityFor()` (same config keys, same central fallback) rather than
     * calling it: the gate is a request-scoped access check and this is read at registration.
     */
    public static function abilityFor(string $realm, RealmRegistry $realms): string
    {
        $gates = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));
        $declared = $gates[$realm]['entitlement'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return 'entitlement:'.$declared;
        }

        if ($realms->tryResolve($realm)?->central === true) {
            return 'entitlement:'.RealmEntitlementResourceGate::CentralRealmEntitlement;
        }

        return self::OPEN_ABILITY;
    }
}
