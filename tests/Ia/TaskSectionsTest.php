<?php

namespace Splicewire\Beam\Ux\Tests\Ia;

use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ux-walkthrough IA-10 / **I3** (UX-09): every operator-realm row sits under exactly one declared task section. The
 * realm's dashboard leaf leads the rail on its own; any other row at the top level, under a host's own section that no
 * `NavSection` declares, or under two declared sections, breaks it. Only the operator realm is judged.
 */
class TaskSectionsTest extends TestCase
{
    private function declare(string $key, string $realm = 'operator', NavAudience $audience = NavAudience::Product): void
    {
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(
                key: $key, realm: $realm, label: ucfirst($key), icon: 'Square', href: '/'.$key, order: 10,
                entitlement: null, permission: null, audience: $audience,
            ),
            by: 'test',
        );
    }

    private static function section(string $key, array $children): NavLink
    {
        return NavLink::make(title: ucfirst($key), routeName: $key.'.section')
            ->stamped(active: false, activeTrail: false, children: $children);
    }

    private static function row(string $path): NavLink
    {
        return NavLink::make(title: $path, href: '/operator/'.$path, routeName: $path.'.index');
    }

    /** @return array<string, string> the I3 violations only */
    private function i3(string $realm, NavTree $tree): array
    {
        return array_filter(
            $this->app->make(IaInvariants::class)->violations($realm, $tree->toArray()),
            fn (string $why, string $id): bool => str_starts_with($id, 'I3'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function test_i3_holds_for_the_dashboard_leaf_and_rows_under_declared_sections_and_the_developer_zone(): void
    {
        $this->declare('billing');
        $this->declare('ops', audience: NavAudience::Developer);

        $tree = NavTree::make([
            NavLink::make(title: 'Dashboard', href: '/operator/dashboard', routeName: 'operator-dashboard.index'),
            self::section('billing', [self::row('usage'), self::row('plans')]),
            NavLink::make(title: 'Developer', routeName: 'developer.section')->inZone('meta')
                ->stamped(active: false, activeTrail: false, children: [self::section('ops', [self::row('meters')])]),
        ]);

        $this->assertSame([], $this->i3('operator', $tree));
    }

    public function test_i3_a_row_outside_every_declared_section_breaks_it(): void
    {
        $this->declare('billing');

        $tree = NavTree::make([
            self::row('stray'),
            // A host's own section that no NavSection declares, which is what OperatorRailSeat's 'platform' was not
            // (it registered one) and what the flagship's hand-built Platform section was.
            self::section('platform', [self::row('conduits')]),
            self::section('billing', [self::section('billing', [self::row('nested')])]),
        ]);

        $this->assertSame([
            'I3 operator /operator/stray',
            'I3 operator /operator/conduits',
            'I3 operator /operator/nested',
        ], array_keys($this->i3('operator', $tree)));
    }

    public function test_i3_judges_the_operator_realm_only(): void
    {
        $tree = NavTree::make([NavLink::make(title: 'Studio', href: '/studio', routeName: 'studio.index')]);

        $this->assertSame([], $this->i3('tenant', $tree));
    }

    public function test_i3_runs_in_the_bound_invariants(): void
    {
        $this->assertContains('I3', $this->app->make(IaInvariants::class)->ids());
    }
}
