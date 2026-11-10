<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Dashboard\SectionOverview;

/**
 * The Site area's gate, in the TENANT realm, where it narrows something: there the diagnostics read ability is a
 * workspace's entry editors (`beam-ux-entry.update`), and a member may hold `analytics.view` without it. In the operator
 * realm the narrowing would prove nothing, because `entitlement:os.operate` admits that realm and the diagnostics alike.
 *
 * Frame is mounted for the tenant realm the way a host mounts it, with `realm` as the routes' default.
 */
class SiteAreaGateTest extends SiteAreaTestCase
{
    protected function realm(): string
    {
        return 'tenant';
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'frame/')) {
                $route->defaults('realm', 'tenant');
            }
        }

        // The host's own rule for its ungated tenant realm: every reader here is a member of this workspace. The kit's
        // default asks a current team, which this bare harness has none of; a host defines the realm's dashboard ability.
        \Illuminate\Support\Facades\Gate::define(
            \Splicewire\Beam\Dashboard\RealmDashboard::abilityFor('tenant', $this->app->make(\Splicewire\Beam\Realm\RealmRegistry::class)),
            fn ($user = null): bool => $user !== null,
        );

        // The Analytics seat is registered as laravel-beam-analytics boots, after beam-ux drew its section overviews.
        $provider = $this->app->getProvider(\Splicewire\Beam\Ux\BeamUxServiceProvider::class);
        (new \ReflectionMethod($provider, 'registerSectionOverviews'))->invoke($provider);

        $this->seedSite();
    }

    private function overviewIds(): array
    {
        return array_column($this->getJson('frame/resources/'.SectionOverview::keyFor('tenant', 'analytics'))->assertOk()->json('data'), 'id');
    }

    /** @return list<string> every routeName in the manifest's nav, at any depth */
    private function navRouteNames(): array
    {
        $names = [];
        $walk = function (array $nodes) use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                if (isset($node['routeName'])) {
                    $names[] = $node['routeName'];
                }
                $walk($node['children'] ?? []);
            }
        };
        $walk($this->getJson('frame/manifest')->assertOk()->json('nav.items') ?? []);

        return $names;
    }

    public function test_a_reader_with_analytics_view_but_not_the_diagnostics_ability_gets_no_card_no_tile_and_a_refused_page(): void
    {
        $this->actingAs($this->reader(11, ['analytics.view']));

        $this->getJson('frame/resources/analytics-site')->assertForbidden();
        $this->getJson('frame/resources/analytics-site/summary')->assertForbidden();

        $ids = $this->overviewIds();
        $this->assertContains('summary:analytics-measurement', $ids, 'control: this reader reads the Analytics overview');
        $this->assertNotContains('summary:analytics-site', $ids, 'no Site card');
        $this->assertNotContains('nav:analytics-site.index', $ids, 'no Site tile');
        $this->assertNotContains('analytics-site.index', $this->navRouteNames(), 'no Site leaf on the rail');
    }

    public function test_a_reader_with_analytics_view_and_the_diagnostics_ability_gets_the_card_the_leaf_and_the_page(): void
    {
        $this->actingAs($this->reader(12, ['analytics.view'], editor: true));

        $this->getJson('frame/resources/analytics-site')->assertOk();
        $this->assertContains('summary:analytics-site', $this->overviewIds());
        $this->assertContains('analytics-site.index', $this->navRouteNames());
    }
}
