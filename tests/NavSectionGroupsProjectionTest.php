<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\DataNav\InvocableNavItem;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;

/**
 * analytics-area 22 (owner ruling 2026-11-12): a package section's declared `groups` reach the collector in the seat's
 * input, exactly where the flagship's `AppNavigation::section(groups:)` puts a host's, so one fold serves both. A seat
 * that declares none keeps the input it always had: no `groups` key at all.
 */
class NavSectionGroupsProjectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    private function declare(string $key, array $groups = []): void
    {
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(
                key: $key, realm: 'tenant', label: ucfirst($key), icon: 'ChartBar', href: '/'.$key, order: 10,
                entitlement: null, permission: null, audience: NavAudience::Product, groups: $groups,
            ),
            by: 'splicewire/laravel-beam-'.$key,
        );
    }

    private function seat(string $key): InvocableNavItem
    {
        foreach ($this->app->make(NavSectionProjector::class)->project('tenant') as $seat) {
            if ($seat instanceof InvocableNavItem && ($seat->input['section'] ?? null) === $key) {
                return $seat;
            }
        }
        $this->fail("no projected seat [{$key}]");
    }

    public function test_declared_groups_ride_in_the_seats_input(): void
    {
        $groups = [['label' => 'Traffic detail', 'match' => 'reports/traffic']];
        $this->declare('reports', $groups);

        $this->assertSame($groups, $this->seat('reports')->input['groups']);
    }

    public function test_a_seat_without_groups_keeps_its_input_unchanged(): void
    {
        $this->declare('reports');

        $this->assertSame(['section', 'realm', 'static'], array_keys($this->seat('reports')->input));
    }
}
