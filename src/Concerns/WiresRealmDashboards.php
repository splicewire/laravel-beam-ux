<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;

/**
 * One concern of {@see BeamUxServiceProvider}: every registered realm gets a `{realm}-dashboard`
 * read-only resource, a member of that realm only, backed by {@see DashboardBacking} carrying the realm
 * (realm-dashboards ticket 04). A fresh host gets one dashboard per realm by writing no config at all.
 *
 * ## Registered imperatively, now AND after every provider has booted
 *
 * The declaration cannot be an attribute: the backing is an INSTANCE carrying its realm
 * (`BackingResolver` takes an instance as-is; the attribute slot is a string), and there is one
 * declaration per realm the HOST registered. Two moments matter, and one sweep serves both:
 *
 *  - **At this boot link**, for every realm known by then — the registry's base realms and whatever
 *    beam's provider declared. This is the moment that counts for a host's ROUTE FILE: the framework's
 *    `RouteServiceProvider` loads routes in its own provider-level `booted()`, i.e. right after the LAST
 *    provider boots and BEFORE any `Application::booted()` callback — and the starters derive their
 *    console segments in `routes/web.php` from `RouteContextProjector::hrefs('operator')` at load time.
 *    Measured at every starter against the first version, which deferred everything: the dashboard leaf
 *    was absent at load, the `{frameRoute}` constraint omitted `dashboard`, and `/operator/dashboard` 404ed.
 *  - **On `Application::booted()`**, once more, for a realm a HOST provider registers in its own boot()
 *    (after this link). The sweep is idempotent — a realm whose dashboard is already registered is
 *    skipped — so running it twice registers nothing twice.
 *
 * **Known limit, and it follows from those two moments:** a realm a host provider registers in its own
 * `boot()` gets its dashboard on `Application::booted()`, which is AFTER the route loader, so a route file
 * reading `RouteContextProjector::hrefs()` for that realm at load time will not see the dashboard leaf —
 * the host must register such a realm before this boot link (a `register()`, or a provider ordered ahead)
 * for its route file to see it; the base realms, registered at the first moment above, are unaffected.
 *
 * ## The read gate is a decision written on the declaration
 *
 * `policy:` is {@see RealmDashboard::abilityFor()} — the realm gate's own ability string, so the dashboard
 * opens no door its realm does not. For a realm that gates nothing, that is {@see RealmDashboard::OPEN_ABILITY},
 * defined HERE as "an authenticated principal is present": the same population an undeclared model-less
 * read admits, spelled as a declaration so `ModelLessReadGateAudit` reads a decision. A host narrows it by
 * redefining the ability from its own provider. The per-row {@see \Splicewire\Beam\Authorization\ResourceVisibility::listable()}
 * filter inside the backing is the dashboard's second gate.
 */
trait WiresRealmDashboards
{
    #[Chained('boot', order: 58)]
    protected function bootRealmDashboards(): void
    {
        if (! $this->app->bound(ParticleResourceRegistry::class) || ! $this->app->bound(RealmRegistry::class)) {
            return;
        }

        $this->app->make(Gate::class)->define(
            RealmDashboard::OPEN_ABILITY,
            fn (?Authenticatable $user = null): bool => $user !== null,
        );

        $this->registerRealmDashboards();
        $this->app->booted(fn () => $this->registerRealmDashboards());
    }

    /**
     * One `{realm}-dashboard` per registered realm that has none yet — see the trait docblock for the two
     * moments this runs at.
     */
    private function registerRealmDashboards(): void
    {
        $realms = $this->app->make(RealmRegistry::class);
        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach (array_keys($realms->all()) as $realm) {
            if ($registry->has(RealmDashboard::keyFor($realm))) {
                continue;
            }

            $registry->register(new ParticleResource(
                key: RealmDashboard::keyFor($realm),
                backing: new DashboardBacking($realm),
                data: DashboardCardRowData::class,
                // Not filterable: the row set is the realm's card list, and a `filter[...]` bag
                // would promise a query the backing does not have.
                filterable: false,
                label: RealmDashboard::LABEL,
                icon: 'LayoutDashboard',
                // The realm gate's ability — see the trait docblock. Declared, never omitted.
                policy: RealmDashboard::abilityFor($realm, $realms),
                // The nav leaf's order among the realm's seats (`NavSection::compare()`): a host seat
                // declaring `order <= 0` may precede it — that is the rule, not an exception to it.
                navOrder: 0,
                routeName: RealmDashboard::routeNameFor($realm),
                readOnly: true,
                editable: false,
                // No per-record page: a card is a projection of ANOTHER resource, whose own
                // list is the place to open it. The router emits a `mounts: 'list'` leaf and no
                // `:id` twin off these two flags.
                showable: false,
                frame: true,
            ), [$realm], by: 'splicewire/laravel-beam-ux');
        }
    }
}
