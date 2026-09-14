<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\ServiceProvider;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Splicewire\Beam\Ux\Particle\Backing\DashboardBacking;

/**
 * Two boot-order traps of the per-realm dashboard (realm-dashboards ticket 04 review), each with the
 * provider order that USED to lose:
 *
 *  - frame's provider registered AFTER beam's, so frame's permit-everything `OpenResourceAccessGate`
 *    was the last `ResourceAccessGate` binding — beam re-binds the realm gate on `booted()`;
 *  - a realm a HOST provider registers in its own boot(), after beam-ux's boot link has run — the
 *    `Application::booted()` sweep gives the host realm its dashboard too;
 *  - a host ROUTE FILE reading `RouteContextProjector::hrefs('operator')` at load time — which the
 *    framework does from the last provider's own booted hook, BEFORE `Application::booted()` — sees the
 *    dashboard leaf, because the base realms' dashboards are registered at beam-ux's boot link itself.
 */
class RealmDashboardRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class, HostRealmProvider::class];
    }

    public function test_beams_realm_gate_wins_when_frames_provider_registers_after_it(): void
    {
        $this->assertInstanceOf(RealmEntitlementResourceGate::class, $this->app->make(ResourceAccessGate::class));
    }

    /**
     * The starter's shape: `routes/web.php` derives the operator console's `{frameRoute}` segments from
     * `hrefs('operator')` while routes load. {@see HostRealmProvider} is the LAST provider, and it reads the
     * projection from its provider-level `booted()` — the same hook `RouteServiceProvider::register()` loads
     * routes from — so this is what a route file sees.
     */
    public function test_a_route_file_reading_the_projection_at_load_time_sees_the_dashboard_leaf(): void
    {
        $this->assertNotNull(HostRealmProvider::$hrefsAtRouteLoad, 'the route-load read never ran');
        $this->assertSame('/operator/dashboard', HostRealmProvider::$hrefsAtRouteLoad['operator-dashboard.index'] ?? null);
        $this->assertContains('dashboard', HostRealmProvider::$segmentsAtRouteLoad);
    }

    public function test_a_realm_a_host_provider_registers_at_boot_gets_a_dashboard(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

        $this->assertTrue($this->app->make(RealmRegistry::class)->tryResolve('partner') !== null);
        $this->assertTrue($registry->has(RealmDashboard::keyFor('partner')));
        $this->assertSame(['partner'], $registry->realmsFor('partner-dashboard'));

        $resource = $registry->find('partner-dashboard');
        $this->assertInstanceOf(DashboardBacking::class, $resource->backing);
        $this->assertSame('partner', $resource->backing->realm);
        // A non-central realm with no declared gate: the open ability, written down.
        $this->assertSame(RealmDashboard::OPEN_ABILITY, $resource->policy);
        $this->assertSame(RealmDashboard::LABEL, $resource->label);
    }
}

class HostRealmProvider extends ServiceProvider
{
    /** @var array<string, string>|null */
    public static ?array $hrefsAtRouteLoad = null;

    /** @var list<string> */
    public static array $segmentsAtRouteLoad = [];

    public function register(): void
    {
        // Exactly the framework's shape: `RouteServiceProvider::register()` queues route loading on the
        // PROVIDER's booted hook, which fires right after this provider's boot() — before Application::booted().
        $this->booted(function (): void {
            $hrefs = $this->app->make(RouteContextProjector::class)->hrefs('operator');

            self::$hrefsAtRouteLoad = $hrefs;
            self::$segmentsAtRouteLoad = array_values(array_unique(array_map(
                fn (string $href): string => explode('/', trim(str_replace('/operator', '', $href), '/'))[0],
                $hrefs,
            )));
        });
    }

    public function boot(): void
    {
        $this->app->make(RealmRegistry::class)->register(
            new RealmDefinition(key: 'partner', routeBase: '/partner', guard: null, central: false),
            by: self::class,
        );
    }
}
