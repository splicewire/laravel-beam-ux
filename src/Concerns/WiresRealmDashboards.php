<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Dashboard\SectionOverview;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Data\DashboardCardRowData;
use Splicewire\Beam\Ux\Frame\SectionOverviewGate;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;
use Throwable;

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
 * opens no door its realm does not. For a realm that declares no entitlement,
 * {@see RealmDashboard::openAbilityFor()} names a realm-specific ability defined here: tenant requires
 * current-team membership; site requires that membership AND `ux.site.author`; user admits the signed-in
 * actor to their own projection. An unknown realm is denied until its host explicitly defines the named
 * ability. The per-row {@see \Splicewire\Beam\Authorization\ResourceVisibility::listable()} filter inside
 * the backing is the dashboard's second gate.
 *
 * ## A flagged section gets the same resource, confined (ADR-0222 decision 9)
 *
 * The same two moments register one `{realm}-{section}-overview` per flagged {@see NavSection}, over the same backing with
 * a section. A package that seats its section in its own boot chain, before this link, gets its leaf in time for a host's
 * route file. A seat declared later gets it on `Application::booted()`, with the known limit above. The console's URL
 * constraint is unaffected either way, because {@see \Splicewire\Beam\Ux\Frame\RealmConsoleSegments} reads seat hrefs too.
 */
trait WiresRealmDashboards
{
    #[Chained('boot', order: 58)]
    protected function bootRealmDashboards(): void
    {
        if (! $this->app->bound(ParticleResourceRegistry::class) || ! $this->app->bound(RealmRegistry::class)) {
            return;
        }

        $this->registerRealmDashboards();
        $this->registerSectionOverviews();
        $this->app->booted(function (): void {
            $this->registerRealmDashboards();
            $this->registerSectionOverviews();
        });
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
            $this->defineOpenRealmAbility($realm, $realms);

            if ($registry->has(RealmDashboard::keyFor($realm))) {
                continue;
            }

            $registry->register(new ParticleResource(
                key: RealmDashboard::keyFor($realm),
                backing: new DashboardBacking($realm),
                data: DashboardCardRowData::class,
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

    /**
     * One overview per flagged seat that has none yet. Idempotent, like the sweep above.
     */
    private function registerSectionOverviews(): void
    {
        if (! $this->app->bound(NavSectionRegistry::class)) {
            return;
        }

        $sections = $this->app->make(NavSectionRegistry::class);
        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach (array_keys($this->app->make(RealmRegistry::class)->all()) as $realm) {
            foreach (SectionOverview::flagged($realm, $sections) as $section) {
                $key = SectionOverview::keyFor($realm, $section->key);

                if ($registry->has($key)) {
                    continue;
                }

                $this->defineSectionOverviewAbility($section);

                $registry->register(new ParticleResource(
                    key: $key,
                    backing: new DashboardBacking($realm, $section->key),
                    data: DashboardCardRowData::class,
                    label: $section->label,
                    icon: $section->icon,
                    policy: SectionOverview::abilityFor($realm, $section->key),
                    routeName: SectionOverview::routeNameFor($realm, $section->key),
                    readOnly: true,
                    editable: false,
                    showable: false,
                    frame: true,
                ), [$realm], by: 'splicewire/laravel-beam-ux');
            }
        }
    }

    /** The gate an overview declares. A host may narrow it by defining the ability first. */
    private function defineSectionOverviewAbility(NavSection $section): void
    {
        $gate = $this->app->make(Gate::class);
        $ability = SectionOverview::abilityFor($section->realm, $section->key);

        if (! $gate->has($ability)) {
            $gate->define($ability, fn (?Authenticatable $user = null): bool => $this->app->make(SectionOverviewGate::class)->allows($user, $section));
        }
    }

    /**
     * Define the kit's secure default for an ungated realm, without replacing a host's explicit rule.
     */
    private function defineOpenRealmAbility(string $realm, RealmRegistry $realms): void
    {
        $ability = RealmDashboard::abilityFor($realm, $realms);

        // Older supported beam-core releases use one shared authenticated fallback. Keep that
        // contract when adopting Releases alone; newer cores retain their realm-specific gates.
        if (! method_exists(RealmDashboard::class, 'openAbilityFor')) {
            $gate = $this->app->make(Gate::class);
            if ($ability === RealmDashboard::OPEN_ABILITY && ! $gate->has($ability)) {
                $gate->define($ability, fn (?Authenticatable $user = null): bool => $user !== null);
            }

            return;
        }

        if ($ability !== RealmDashboard::openAbilityFor($realm)) {
            return;
        }

        $gate = $this->app->make(Gate::class);

        if ($gate->has($ability)) {
            return;
        }

        $gate->define($ability, function (?Authenticatable $user = null) use ($realm, $gate): bool {
            if ($user === null) {
                return false;
            }

            if ($realm === 'user') {
                return true;
            }

            if (! in_array($realm, ['tenant', 'site'], true)) {
                return false;
            }

            try {
                $team = BeamAccounts::currentTeam();
            } catch (Throwable) {
                return false;
            }

            if (! $team instanceof TeamContract || ! $team->hasMember($user)) {
                return false;
            }

            return $realm === 'tenant' || $gate->forUser($user)->allows('ux.site.author');
        });
    }
}
