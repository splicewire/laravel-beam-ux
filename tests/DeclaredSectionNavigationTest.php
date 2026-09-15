<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Schemastud\Frame\Registry\RouteContextEntry;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\DeclaredSectionNavigation;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\FrameResourcesInvocable;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;

/**
 * The seat half of package-contributed navigation: a package declares a {@see NavSection} into
 * beam's `beam.nav.sections`, and this package turns it into a navigation the host never wrote.
 *
 * ⚠️ **Measured before this existed:** 9 family packages declared 30 nav sections, and 11 of them
 * (`ops` x6, `calendars` x3, `authoring` x2) named a section NO host seated. They were correctly
 * declared and invisible, because a package could contribute nav CHILDREN and could not seat the
 * section those children hang under.
 */
class DeclaredSectionNavigationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    private function sections(): NavSectionRegistry
    {
        return $this->app->make(NavSectionRegistry::class);
    }

    /** The projected seat with this section key, or null — beam-ux seats its own alongside. */
    private function seatFor(string $realm, string $key): ?InvocableNavItem
    {
        foreach ($this->app->make(NavSectionProjector::class)->project($realm) as $seat) {
            if (($seat->input['section'] ?? null) === $key) {
                return $seat;
            }
        }

        return null;
    }

    private function declare(string $key, string $realm, int $order = 10): void
    {
        $this->sections()->register(
            new NavSection(
                key: $key, realm: $realm, label: ucfirst($key),
                icon: 'Calendar', href: '/'.$key, order: $order,
                entitlement: null, permission: null,
            ),
            by: 'splicewire/laravel-beam-'.$key,
        );
    }

    public function test_a_declared_section_projects_into_a_seat_pointing_at_the_collector(): void
    {
        $this->declare('calendars', 'tenant');

        // beam-ux seats its OWN `ops`/`authoring` in both realms, so this asserts on the declared
        // seat by key rather than on an empty registry — the package under test is also a registrant.
        $seat = $this->seatFor('tenant', 'calendars');

        $this->assertInstanceOf(InvocableNavItem::class, $seat);
        $this->assertSame('Calendars', $seat->title);
        $this->assertSame(FrameResourcesInvocable::NAME, $seat->invocable);
        $this->assertSame('calendars', $seat->input['section']);
    }

    /**
     * `*.section` is the spelling {@see \Splicewire\Beam\Ux\Frame\RouteContextValidator} exempts from
     * leaf binding. A seat spelled any other way would make every manifest throw the moment a package
     * declared one, which is the failure this naming exists to prevent — so it is pinned, not assumed.
     */
    public function test_a_seat_is_named_so_the_validator_can_never_reject_it(): void
    {
        $this->declare('ops', 'operator');

        $this->assertSame('ops.section', $this->seatFor('operator', 'ops')->routeName);
    }

    /** A realm no package targeted is empty, not an error. */
    public function test_an_untargeted_realm_projects_nothing(): void
    {
        $this->assertSame([], $this->app->make(NavSectionProjector::class)->project('site'));
    }

    /**
     * The override seam. A host registering its own navigation for the realm supersedes ours, and
     * {@see DeclaredSectionNavigation} is how the contributor tells the two apart AFTER the fact —
     * node-level provenance cannot work, because `NavRegistry::build()` strips the `#[Hidden]` meta
     * bag on its active-stamping round-trip.
     */
    public function test_a_host_registering_its_own_navigation_supersedes_the_declared_one(): void
    {
        $navigations = $this->app->make(NavRegistry::class);
        $projector = $this->app->make(NavSectionProjector::class);

        $navigations->register('tenant', new DeclaredSectionNavigation($projector, 'tenant'), by: 'pkg');
        $this->assertInstanceOf(DeclaredSectionNavigation::class, $navigations->tryResolve('tenant'));

        $navigations->register('tenant', fn (): array => [NavLink::make(title: 'Host', href: '/host')], by: 'host');

        $this->assertNotInstanceOf(DeclaredSectionNavigation::class, $navigations->tryResolve('tenant'));
    }

    /**
     * The rule that keeps a contributed node from 500ing a host: a node naming a route this host does
     * not mount is DROPPED, where a host's own such node still throws. `*.section` survives because
     * the validator never asks it to bind.
     */
    public function test_pruning_drops_an_unbound_contributed_node_and_keeps_the_bound_and_the_section(): void
    {
        $contribution = $this->app->make(FrameNavContribution::class);

        $prune = new \ReflectionMethod($contribution, 'pruneUnbound');
        $prune->setAccessible(true);

        $tree = NavTree::make([
            InvocableNavItem::make(title: 'Calendars', invocable: FrameResourcesInvocable::NAME, routeName: 'calendars.section')
                ->stamped(active: false, activeTrail: false, children: [
                    NavLink::make(title: 'Mounted', href: '/c', routeName: 'calendars.index'),
                    NavLink::make(title: 'Not mounted', href: '/x', routeName: 'nope.index'),
                ]),
        ]);

        $pruned = $prune->invoke($contribution, [new RouteContextEntry(routeName: 'calendars.index', path: 'calendars')], $tree);

        $this->assertCount(1, $pruned->items, 'the section header survives — the validator exempts it');
        $this->assertCount(1, $pruned->items[0]->children, 'only the unbound child is dropped');
        $this->assertSame('Mounted', $pruned->items[0]->children[0]->title);
    }

    /**
     * ⚠️ **The mutation that exposed this test's absence.** Making `contributeNav()` prune
     * unconditionally — `if (true)` in place of the `instanceof DeclaredSectionNavigation` check —
     * left the whole suite GREEN. Pruning a HOST's nav is a real regression: the host author's
     * mis-spelled `routeName` would stop throwing and silently vanish from their own sidebar, which
     * is the one case {@see \Splicewire\Beam\Ux\Frame\RouteContextValidator}'s throw exists to catch
     * and the one case package contribution must not weaken. Two other mutations went red; this
     * safety property had nothing pinning it at all.
     */
    public function test_a_hosts_own_unbound_route_still_throws_rather_than_being_pruned(): void
    {
        $this->app->make(NavRegistry::class)->register(
            'tenant',
            fn (): array => [NavLink::make(title: 'Typo', href: '/typo', routeName: 'tpyo.index')],
            by: 'host',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tpyo\.index/');

        $this->app->make(FrameNavContribution::class)->contributeNav('tenant');
    }

    /**
     * Seat one section for the `tenant` realm carrying the given hand-authored rows.
     *
     * @param  list<array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>  $static
     */
    private function declareWithStatic(array $static, string $key = 'platform'): void
    {
        $this->sections()->register(
            new NavSection(
                key: $key, realm: 'tenant', label: 'Platform',
                icon: 'Server', href: '/'.$key, order: 10,
                entitlement: null, permission: null, static: $static,
            ),
            by: 'app',
        );
    }

    /**
     * Every href the projection puts in front of a reader for one realm — the top-level nodes AND the
     * hand-authored rows each seat hands the collector, which is where a static child's href lives
     * before {@see FrameResourcesInvocable} expands it. Counting only the top level would miss the
     * duplicate entirely, because the two Dashboards sat on different rungs.
     *
     * @return list<string>
     */
    private function projectedHrefs(string $realm): array
    {
        $hrefs = [];

        foreach ($this->app->make(NavSectionProjector::class)->project($realm, new NavContext(user: new User)) as $node) {
            $hrefs[] = (string) $node->href;

            foreach (($node instanceof InvocableNavItem ? $node->input['static'] ?? [] : []) as $row) {
                $hrefs[] = (string) ($row['href'] ?? '');
            }
        }

        return $hrefs;
    }

    /**
     * ⚠️ **Measured at `beam.test` and `satellite.test` (realm-dashboards ticket 09):** the account rail
     * carried *Platform › Dashboard* — the authored `resources/beam-ux/nav.yml` row, handed to a host
     * seat as a static child — beside the realm-level Dashboard leaf beam-ux generates. Both hrefs were
     * `/dashboard`, so a reader saw the same destination twice on two different rungs.
     *
     * Neither author can fix it alone: the host's `nav.yml` is its statement of which pages exist and
     * must not have to know a package now seats one of them, and the `{realm}-dashboard` registration
     * is a package fact that cannot learn what a host authored. So the projection collapses them, and
     * the survivor is the generated leaf at the leaf's own position.
     */
    public function test_an_authored_row_at_the_dashboard_href_collapses_into_the_generated_leaf(): void
    {
        $this->declareWithStatic([
            ['title' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'Home'],
            ['title' => 'Connectors', 'href' => '/connectors'],
        ]);

        $hrefs = $this->projectedHrefs('tenant');

        $this->assertSame(['/dashboard'], array_values(array_filter($hrefs, fn (string $h): bool => $h === '/dashboard')));
        $this->assertContains('/connectors', $hrefs, 'the seat keeps every other authored row');
    }

    /**
     * The survivor is the LEAF — its position and its `routeName`, not the nested row's — wearing the
     * host's own words for its own page. A host that titled the row "Home" keeps "Home"; the icon
     * rides along for the same reason.
     */
    public function test_the_surviving_leaf_keeps_its_place_and_wears_the_authored_label_and_icon(): void
    {
        $this->declareWithStatic([['title' => 'Home', 'href' => '/dashboard', 'icon' => 'House']]);

        $nodes = $this->app->make(NavSectionProjector::class)->project('tenant', new NavContext(user: new User));

        $this->assertSame('Home', $nodes[0]->title);
        $this->assertSame('House', $nodes[0]->icon);
        $this->assertSame('/dashboard', $nodes[0]->href);
        $this->assertSame(RealmDashboard::routeNameFor('tenant'), $nodes[0]->routeName);
    }

    /**
     * An authored row that is not the dashboard is none of this projection's business — asserted with a
     * principal in hand, so the leaf DOES exist and the collapse is genuinely declining rather than
     * absent.
     */
    public function test_an_authored_row_at_a_different_href_is_untouched(): void
    {
        $row = ['title' => 'Connectors', 'href' => '/connectors', 'icon' => 'Plug', 'navOrder' => 5];
        $this->declareWithStatic([$row]);

        $nodes = $this->app->make(NavSectionProjector::class)->project('tenant', new NavContext(user: new User));

        $this->assertSame('/dashboard', $nodes[0]->href, 'the leaf is present, so the collapse arm was live');
        $this->assertSame([$row], $this->seatIn($nodes, 'platform')->input['static']);
    }

    /** The projected seat with this section key, from an already-built projection. */
    private function seatIn(array $nodes, string $key): InvocableNavItem
    {
        foreach ($nodes as $node) {
            if ($node instanceof InvocableNavItem && ($node->input['section'] ?? null) === $key) {
                return $node;
            }
        }

        $this->fail("no seat [{$key}] in the projection");
    }

    /**
     * A seat whose resources all live in a DIFFERENT realm at this host renders nothing rather than a
     * dead header. The package cannot know which realm applies — beam-ux's own `ops` resources sit in
     * `operator` at the flagship and in `tenant` at the starter, because realm membership is the
     * host's `config/frame.realms` list. Declaring both realms and dropping the empty one is the only
     * honest way for a package to say "wherever these ended up".
     */
    public function test_an_empty_contributed_seat_is_dropped_rather_than_rendered_as_a_dead_header(): void
    {
        $contribution = $this->app->make(FrameNavContribution::class);

        $prune = new \ReflectionMethod($contribution, 'pruneUnbound');
        $prune->setAccessible(true);

        $tree = NavTree::make([
            InvocableNavItem::make(title: 'Full', invocable: FrameResourcesInvocable::NAME, routeName: 'full.section')
                ->stamped(active: false, activeTrail: false, children: [
                    NavLink::make(title: 'Child', href: '/c', routeName: 'kept.index'),
                ]),
            InvocableNavItem::make(title: 'Empty', invocable: FrameResourcesInvocable::NAME, routeName: 'empty.section')
                ->stamped(active: false, activeTrail: false, children: []),
        ]);

        $pruned = $prune->invoke($contribution, [new RouteContextEntry(routeName: 'kept.index', path: 'c')], $tree);

        $this->assertCount(1, $pruned->items);
        $this->assertSame('Full', $pruned->items[0]->title);
    }
}
