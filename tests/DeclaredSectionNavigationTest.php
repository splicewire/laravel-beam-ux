<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Schemastud\Frame\Registry\RouteContextEntry;
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

        $seats = $this->app->make(NavSectionProjector::class)->project('tenant');

        $this->assertCount(1, $seats);
        $this->assertInstanceOf(InvocableNavItem::class, $seats[0]);
        $this->assertSame('Calendars', $seats[0]->title);
        $this->assertSame(FrameResourcesInvocable::NAME, $seats[0]->invocable);
        $this->assertSame('calendars', $seats[0]->input['section']);
    }

    /**
     * `*.section` is the spelling {@see \Splicewire\Beam\Ux\Frame\RouteContextValidator} exempts from
     * leaf binding. A seat spelled any other way would make every manifest throw the moment a package
     * declared one, which is the failure this naming exists to prevent — so it is pinned, not assumed.
     */
    public function test_a_seat_is_named_so_the_validator_can_never_reject_it(): void
    {
        $this->declare('ops', 'operator');

        $seats = $this->app->make(NavSectionProjector::class)->project('operator');

        $this->assertSame('ops.section', $seats[0]->routeName);
    }

    /** A realm no package targeted is empty, not an error. */
    public function test_an_untargeted_realm_projects_nothing(): void
    {
        $this->assertSame([], $this->app->make(NavSectionProjector::class)->project('tenant'));
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
}
