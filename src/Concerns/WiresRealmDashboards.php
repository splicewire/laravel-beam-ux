<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Frame\RealmDashboard;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;

/**
 * One concern of {@see BeamUxServiceProvider}: every registered realm gets a `{realm}-dashboard`
 * read-only resource, a member of that realm only, backed by {@see DashboardBacking} carrying the realm
 * (realm-dashboards ticket 04). A fresh host gets one dashboard per realm by writing no config at all.
 *
 * ## Registered imperatively, and after EVERY provider has booted
 *
 * The declaration cannot be an attribute: the backing is an INSTANCE carrying its realm
 * (`BackingResolver` takes an instance as-is; the attribute slot is a string), and there is one
 * declaration per realm the HOST registered. Realms are registered by host providers, which boot after
 * every package's — so enumerating {@see RealmRegistry::all()} inside this boot link would miss any realm
 * a host adds at boot. The registration is deferred to `Application::booted()`, which runs once the whole
 * provider set has booted (or immediately, when it already has).
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

        $this->app->booted(function (): void {
            $realms = $this->app->make(RealmRegistry::class);
            $registry = $this->app->make(ParticleResourceRegistry::class);

            foreach (array_keys($realms->all()) as $realm) {
                $registry->register(new ParticleResource(
                    key: RealmDashboard::keyFor($realm),
                    backing: new DashboardBacking($realm),
                    data: DashboardCardRowData::class,
                    // Not filterable: the row set is the realm's card list, and a `filter[...]` bag
                    // would promise a query the backing does not have.
                    filterable: false,
                    label: 'Dashboard',
                    icon: 'LayoutDashboard',
                    // The realm gate's ability — see the trait docblock. Declared, never omitted.
                    policy: RealmDashboard::abilityFor($realm, $realms),
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
        });
    }
}
