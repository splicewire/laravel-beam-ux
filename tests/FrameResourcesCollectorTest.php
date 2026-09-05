<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\DataNav\Contracts\NavExpander;
use Rushing\DataNav\Contracts\NavMatcher;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavGate;
use Rushing\DataNav\NavInvocableRegistry;
use Rushing\DataNav\NavRegistry;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\RouteContextPlan;

/**
 * The promoted nav collector — the capability that attaches a realm's Frame resources to a host's
 * section seat. Like the router half beside it ({@see FrameRouteContextProjectionTest}) it lived at
 * exactly ONE host, so every other beam host declaring a `section` on a resource got nothing.
 *
 * ⚠️ **The fixture is built so that every ordering rule has something to get WRONG.** Within one
 * section: two resources share a `navOrder` and are registered in reverse label order (so the label
 * tiebreak is observable and is not merely the registration order); one resource declares no
 * `navOrder` (so `PHP_INT_MAX` trailing is observable); a static declares `navOrder: 0` (so a
 * static leading resources is observable) and two more share the default (so authored order is
 * observable). A fixture whose resources agree on their order lets a collector that ignored
 * `navOrder`, or sorted resources and statics separately, pass every assertion — this estate's
 * signature defect (AGENTS.md §*an instrument that reports success by not running*).
 */
class FrameResourcesCollectorTest extends TestCase
{
    /**
     * ⚠️ Testbench does not auto-discover, and frame's provider is what binds the
     * {@see ResourceRegistry} port. Without it the interface is unbound, the collector is not
     * constructible, and — because {@see \Splicewire\Beam\Ux\Concerns\WiresFrameNav} guards its
     * boot registration on exactly that binding — the capability would simply be ABSENT and every
     * expansion here would return `[]` through the expander's deliberate degradation. That is the
     * silent failure this note exists to prevent.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Realm membership is a host-side list, and the two realms deliberately do not hold the same
        // one: `audit` is in BOTH sections' host but only in `tenant`, so the realm filter has
        // something to exclude. Without an asymmetry a collector that ignored the realm entirely
        // passes.
        $app['config']->set('frame.realms', [
            'operator' => ['tenants', 'plans', 'packs', 'hooks'],
            'tenant' => ['audit'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A host's IA — the same seam the flagship uses. `packs` gets a host-chosen path so the href
        // join has a case where the leaf URL and the resource KEY disagree; without one the
        // key-derived fallback and the real join produce identical output and the join is untested.
        $this->app->bind(RouteContextPlan::class, fn (): RouteContextPlan => new RouteContextPlan(
            resourcePaths: ['packs' => 'scaffolding/packs'],
            centralStandalone: [['routeName' => 'dashboard.section', 'path' => 'dashboard', 'mounts' => 'detail']],
        ));

        $registry = $this->app->make(ParticleResourceRegistry::class);

        // Registration order is deliberately NOT the expected order: `plans` and `packs` share
        // navOrder 2 and are registered Plans-then-Packs while their labels sort Packs-then-Plans,
        // so the label tiebreak is what decides and registration order cannot be mistaken for it.
        foreach ([
            // key        label        section     navOrder  routeName
            ['tenants', 'Tenants', 'platform', 1, 'tenants.index'],
            ['plans', 'Plans', 'platform', 2, 'plans.index'],
            ['packs', 'Packs', 'platform', 2, 'packs.index'],
            ['hooks', 'Hooks', 'platform', null, 'hooks.index'],
            ['audit', 'Audit', 'platform', 1, 'audit.index'],
        ] as [$key, $label, $section, $navOrder, $routeName]) {
            $registry->register(new ParticleResource(
                key: $key,
                backing: 'Acme\\Particles\\'.ucfirst($key),
                data: CollectorFixtureData::class,
                label: $label,
                section: $section,
                navOrder: $navOrder,
                routeName: $routeName,
            ), by: self::class);
        }
    }

    // ---------------------------------------------------------------- the merged sort

    public function test_one_merged_sort_orders_resources_and_statics_together(): void
    {
        // navOrder: Dashboard 0 (static), Tenants 1, then Packs/Plans tied at 2, then Hooks
        // (undeclared ⇒ PHP_INT_MAX), then the two undeclared statics in AUTHORED order.
        //
        // Until 2026-08-27 statics were sorted separately and appended, which made `navOrder` a
        // resource-only key — no value of it could lift Dashboard above Tenants, and the flagship's
        // operator Dashboard rendered fifth while its own docblock called it head of the section.
        $this->assertSame(
            ['Dashboard', 'Tenants', 'Packs', 'Plans', 'Hooks', 'Zeta', 'Alpha'],
            $this->titles('platform', 'operator', [
                ['title' => 'Zeta', 'href' => '/operator/zeta'],
                ['title' => 'Alpha', 'href' => '/operator/alpha'],
                ['title' => 'Dashboard', 'href' => '/operator/dashboard', 'routeName' => 'dashboard.section', 'navOrder' => 0],
            ]),
        );
    }

    public function test_a_static_declaring_no_nav_order_trails_every_resource(): void
    {
        // The free-migration property: PHP_INT_MAX puts an undeclared static exactly where the old
        // unconditional append put it, which is why merging the two lists moved no existing item.
        $this->assertSame(
            ['Tenants', 'Packs', 'Plans', 'Hooks', 'Connectors'],
            $this->titles('platform', 'operator', [
                ['title' => 'Connectors', 'href' => '/operator/connectors'],
            ]),
        );
    }

    public function test_resources_tied_on_nav_order_break_on_label(): void
    {
        // Packs before Plans: both navOrder 2, registered Plans first. Only the label tiebreak
        // produces this order — registration order would give Plans, Packs.
        $titles = $this->titles('platform', 'operator');

        $this->assertSame(['Tenants', 'Packs', 'Plans', 'Hooks'], $titles);
    }

    public function test_statics_tied_on_nav_order_keep_their_authored_order(): void
    {
        // Both undeclared ⇒ both PHP_INT_MAX. PHP 8's usort is stable, and that stability is what
        // keeps the AUTHORED sequence instead of re-sorting statics by label the way resources are.
        // Written Zeta-then-Alpha precisely so a label sort would visibly flip it.
        $titles = $this->titles('platform', 'operator', [
            ['title' => 'Zeta', 'href' => '/operator/zeta'],
            ['title' => 'Alpha', 'href' => '/operator/alpha'],
        ]);

        $this->assertSame(['Tenants', 'Packs', 'Plans', 'Hooks', 'Zeta', 'Alpha'], $titles);
    }

    // ---------------------------------------------------------------- realm + RBAC

    public function test_a_section_carries_only_the_resources_its_realm_holds(): void
    {
        // `audit` declares the SAME section and a leading navOrder, and is absent from `operator`
        // membership — so a collector that filtered on section alone would put it first here.
        $this->assertNotContains('Audit', $this->titles('platform', 'operator'));
        $this->assertSame(['Audit'], $this->titles('platform', 'tenant'));
    }

    public function test_a_policy_bound_resource_is_view_any_gated_and_a_policy_less_one_is_not(): void
    {
        \Illuminate\Support\Facades\Gate::policy(GatedFixtureModel::class, DenyingViewAnyPolicy::class);

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gated',
            backing: GatedFixtureModel::class,
            data: CollectorFixtureData::class,
            label: 'Gated',
            section: 'platform',
            navOrder: 0,
            routeName: 'gated.index',
        ), by: self::class);

        // Membership is seeded from `config('frame.realms')` at BOOT, so a runtime config write is
        // invisible here — the additive seed method is the mechanism, and the honest one to use.
        $this->app->make(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['gated']]);

        $titles = $this->invoke('platform', 'operator', user: new DenyingUser);

        // Secure by omission for the resource that BINDS a policy…
        $this->assertNotContains('Gated', array_column($titles, 'title'));
        // …and NOT for the ones that do not. Laravel denies an ability nobody defined, so an
        // unconditional `can()` here hid every scope-gated seat as a wrong ABSENCE (measured at the
        // flagship 2026-09-02) — an absence a reader cannot tell from a permissions denial.
        $this->assertSame(['Tenants', 'Packs', 'Plans', 'Hooks'], array_column($titles, 'title'));
    }

    /**
     * ⚠️ **This test asserted the OPPOSITE until 2026-09-05**, under the name
     * `test_gating_is_skipped_entirely_with_no_authenticated_user`: it registered a DENYING `viewAny`
     * policy and pinned that an anonymous reader saw the row anyway. That was deliberate, and it was
     * wrong — measured, not argued.
     *
     * `~/Herd/beam` mounts `/frame/manifest` on `web` with NO auth. An unauthenticated `curl` there
     * returned BOTH nav seats, all five children and 12 resource definitions with labels and hrefs,
     * while an authenticated Demo Owner received ONE seat. Anonymous saw strictly MORE than a
     * logged-in user, over an endpoint anyone can reach — and the collector's own docblock claims
     * secure-by-omission three lines above the arm that did it.
     *
     * A null actor cannot satisfy a `viewAny` policy, so it is denied exactly as a real actor failing
     * that policy is. Anonymous is now bounded above by authenticated. A resource with no model or no
     * `viewAny` policy stays public, because that is a declaration the host made rather than an
     * absence being read as permission.
     */
    public function test_an_anonymous_reader_never_sees_more_than_an_authenticated_one(): void
    {
        \Illuminate\Support\Facades\Gate::policy(GatedFixtureModel::class, DenyingViewAnyPolicy::class);

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gated',
            backing: GatedFixtureModel::class,
            data: CollectorFixtureData::class,
            label: 'Gated',
            section: 'platform',
            navOrder: 0,
            routeName: 'gated.index',
        ), by: self::class);

        // Membership is seeded from `config('frame.realms')` at BOOT, so a runtime config write is
        // invisible here — the additive seed method is the mechanism, and the honest one to use.
        $this->app->make(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['gated']]);

        $this->assertNotContains(
            'Gated',
            array_column($this->invoke('platform', 'operator'), 'title'),
            'a denying viewAny policy must deny the anonymous reader too — otherwise logging in REMOVES rows',
        );
    }

    // ---------------------------------------------------------------- the href join

    public function test_a_resource_href_comes_from_the_leaf_its_route_name_names(): void
    {
        $byTitle = $this->byTitle($this->invoke('platform', 'operator'));

        // `packs` has a host-chosen path, so the leaf URL and the resource KEY disagree. The join is
        // the only derivation that can produce this; the key fallback would say `/operator/packs`.
        $this->assertSame('/operator/scaffolding/packs', $byTitle['Packs']['href']);
        $this->assertSame('operator/scaffolding/packs*', $byTitle['Packs']['match']);
        $this->assertSame('packs.index', $byTitle['Packs']['routeName']);
    }

    public function test_a_static_href_comes_from_the_leaf_too_when_it_names_one(): void
    {
        $byTitle = $this->byTitle($this->invoke('platform', 'operator', static: [
            // Declares a DIFFERENT href from the leaf its routeName names, so which one wins is
            // observable. Both spellings agree in the live estate, which is exactly why deriving it
            // has to be asserted against a fixture where they do not.
            ['title' => 'Dashboard', 'href' => '/operator/stale-dashboard', 'routeName' => 'dashboard.section', 'navOrder' => 0],
        ]));

        $this->assertSame('/operator/dashboard', $byTitle['Dashboard']['href']);
    }

    public function test_an_unjoinable_seat_falls_back_to_the_key_derivation_rather_than_vanishing(): void
    {
        // `orphan` names a routeName no leaf serves — it is folded out of the router projection, so
        // the join misses. A vanishing seat would be indistinguishable from a permissions denial,
        // and secure-by-omission means absence already MEANS something else here.
        $this->app->bind(RouteContextPlan::class, fn (): RouteContextPlan => new RouteContextPlan(
            foldedResources: ['orphan'],
        ));

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'orphan',
            backing: 'Acme\\Particles\\Orphan',
            data: CollectorFixtureData::class,
            label: 'Orphan',
            section: 'platform',
            navOrder: 5,
            routeName: 'orphan.index',
        ), by: self::class);

        $this->app->make(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['orphan']]);

        $byTitle = $this->byTitle($this->invoke('platform', 'operator'));

        // The realm's routeBase, then the KEY — the derivation this method used to do
        // unconditionally, kept for exactly this case.
        $this->assertSame('/operator/orphan', $byTitle['Orphan']['href']);

        // And a static with no routeName at all keeps its declared href, for the same reason:
        // nothing forces a static to be manifest-backed.
        $withStatic = $this->byTitle($this->invoke('platform', 'operator', static: [
            ['title' => 'Bespoke', 'href' => '/operator/bespoke'],
        ]));
        $this->assertSame('/operator/bespoke', $withStatic['Bespoke']['href']);
    }

    public function test_a_realm_this_host_never_registered_declines_instead_of_throwing(): void
    {
        // `RouteContextProjector::hrefs()` opens with `resolve()`, which throws — and "is there a
        // realm called this here" is a fact about the HOST, which the estate rule makes an absence.
        // A section keyed to an unknown realm renders empty; it does not take the nav down.
        $this->assertSame([], $this->invoke('platform', 'no-such-realm'));
    }

    // ---------------------------------------------------------------- the wiring

    public function test_the_package_registers_the_collector_so_a_host_writes_no_code(): void
    {
        // The whole point of the lift: a host that never wrote this class still has the capability,
        // and it resolves through the registry the expander actually reads (data-nav's own root, not
        // the estate-wide pool — the mis-pointing that once turned two nav sections silently empty).
        $this->assertTrue($this->app->make(NavInvocableRegistry::class)->has(FrameResourcesInvocable::NAME));
    }

    public function test_a_host_section_node_expands_through_the_real_registry_and_expander(): void
    {
        // End-to-end through the seam a host actually uses — the collector reached by NAME off an
        // InvocableNavItem, not called directly. An unregistered name degrades to `[]` here rather
        // than erroring, so this case is what distinguishes "wired" from "silently absent".
        $registry = new NavRegistry(
            gate: new NavGate,
            expander: $this->app->make(NavExpander::class),
            matcher: $this->app->make(NavMatcher::class),
        );

        $registry->register('probe', fn (): array => [
            InvocableNavItem::make(
                title: 'Platform',
                invocable: FrameResourcesInvocable::NAME,
                input: ['section' => 'platform', 'realm' => 'operator'],
                href: '/operator',
            ),
        ]);

        $tree = $registry->build('probe', new NavContext(user: null, attributes: ['realm' => 'operator']));

        $this->assertSame(
            ['Tenants', 'Packs', 'Plans', 'Hooks'],
            array_map(fn ($child): string => $child->title, $tree->items[0]->children()),
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<int, array<string, mixed>>  $static
     * @return array<int, array<string, mixed>>
     */
    private function invoke(string $section, string $realm, array $static = [], ?Authenticatable $user = null): array
    {
        // The context is bound into the container exactly as `NavRegistry::build()` binds it for the
        // duration of a build — the collector reads the user off it, not off the auth guard.
        $this->app->instance(NavContext::class, new NavContext(user: $user, attributes: ['realm' => $realm]));

        $output = $this->app->make(FrameResourcesInvocable::class)
            ->invoke(['section' => $section, 'realm' => $realm, 'static' => $static]);

        return $output['items'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $static
     * @return array<int, string>
     */
    private function titles(string $section, string $realm, array $static = []): array
    {
        return array_column($this->invoke($section, $realm, $static), 'title');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function byTitle(array $items): array
    {
        $byTitle = [];

        foreach ($items as $item) {
            $byTitle[$item['title']] = $item;
        }

        return $byTitle;
    }
}

/** A minimal Data class for the fixture resources — `ParticleResource::$data` names the projected shape. */
class CollectorFixtureData extends \Spatie\LaravelData\Data
{
    public function __construct(public string $id = '') {}
}

/** A model-backed fixture, so `ResourceDefinition::$model` is non-null and the viewAny gate engages. */
class GatedFixtureModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'gated_fixtures';
}

/** Binds a `viewAny` that denies — the half of the gate that must still deny. */
class DenyingViewAnyPolicy
{
    public function viewAny(mixed $user): bool
    {
        return false;
    }
}

/** An actor that denies everything, so a seat surviving proves the gate was never consulted. */
class DenyingUser implements Authenticatable, Authorizable
{
    public function can($abilities, $arguments = []): bool
    {
        return false;
    }

    public function cant($abilities, $arguments = []): bool
    {
        return true;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return '1';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
