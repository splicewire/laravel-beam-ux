<?php

namespace Splicewire\Beam\Ux\Tests\Ia;

use Illuminate\Foundation\Auth\User;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ux-walkthrough IA-8 / M4 (UX-08): the projector partitions by audience. Every top-level node is `zone: primary`,
 * except ONE node, `developer.section`, which is `zone: meta` and holds every `developer` seat. I2 holds the partition
 * on any tree: a developer seat never sits in the primary zone, including in a host-authored tree.
 */
class DeveloperZoneTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    private function seat(string $key, NavAudience $audience, int $order = 10): void
    {
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(
                key: $key, realm: 'tenant', label: ucfirst($key), icon: 'Square', href: '/'.$key, order: $order,
                entitlement: null, permission: null, audience: $audience,
            ),
            by: 'test',
        );
    }

    /** @return list<NavNode> the tenant realm's top level, the beam-ux package's own seats excluded */
    private function topLevel(): array
    {
        return array_values(array_filter(
            $this->app->make(NavSectionProjector::class)->project('tenant', new NavContext(user: new User)),
            fn (NavNode $n): bool => ! in_array($n->routeName, ['authoring.section'], true),
        ));
    }

    /** @return list<string> */
    private function childRouteNames(NavNode $node): array
    {
        return array_map(fn (NavNode $n): ?string => $n->routeName, $node->children);
    }

    public function test_product_seats_are_primary_and_developer_seats_gather_under_one_meta_node(): void
    {
        $this->seat('billing', NavAudience::Product, 10);
        $this->seat('registry', NavAudience::Developer, 20);

        $top = $this->topLevel();
        $byRoute = collect($top)->keyBy('routeName');

        $this->assertSame('primary', $byRoute['billing.section']->zone);
        $this->assertArrayNotHasKey('registry.section', $byRoute->all(), 'a developer seat never sits at the top level');

        $developer = $byRoute['developer.section'];
        $this->assertSame('meta', $developer->zone);
        $this->assertContains('registry.section', $this->childRouteNames($developer));
        // beam-ux's own `ops` seat is a developer seat too.
        $this->assertContains('ops.section', $this->childRouteNames($developer));
        $this->assertSame(['primary'], collect($top)->reject(fn (NavNode $n) => $n->routeName === 'developer.section')->pluck('zone')->unique()->values()->all());
    }

    public function test_the_developer_node_is_the_last_top_level_node(): void
    {
        $this->seat('registry', NavAudience::Developer, -5);

        $top = $this->topLevel();

        $this->assertSame('developer.section', end($top)->routeName, 'the Developer zone follows the rail, whatever a seat\'s order');
    }

    public function test_i2_a_developer_seat_in_the_primary_zone_breaks_the_partition(): void
    {
        $this->seat('registry', NavAudience::Developer);
        $invariants = $this->app->make(IaInvariants::class);

        $loose = NavTree::make([
            NavLink::make(title: 'Registry', routeName: 'registry.section')->inZone('primary')
                ->stamped(active: false, activeTrail: false, children: [NavLink::make(title: 'Rows', href: '/registry')]),
        ])->toArray();
        $seated = NavTree::make([
            NavLink::make(title: 'Developer', routeName: 'developer.section')->inZone('meta')->stamped(active: false, activeTrail: false, children: [
                NavLink::make(title: 'Registry', routeName: 'registry.section')
                    ->stamped(active: false, activeTrail: false, children: [NavLink::make(title: 'Rows', href: '/registry')]),
            ]),
        ])->toArray();

        $this->assertSame(['I2 tenant registry.section'], array_keys(array_filter(
            $invariants->violations('tenant', $loose),
            fn (string $why, string $id): bool => str_starts_with($id, 'I2'),
            ARRAY_FILTER_USE_BOTH,
        )));
        $this->assertSame([], array_filter(
            $invariants->violations('tenant', $seated),
            fn (string $why, string $id): bool => str_starts_with($id, 'I2'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function test_i2_runs_in_the_bound_invariants(): void
    {
        $this->assertContains('I2', $this->app->make(IaInvariants::class)->ids());
    }
}
