<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Ux\Data\SiteMapRowData;
use Splicewire\Beam\Ux\Particle\RedirectTracesOp;
use Splicewire\Beam\Ux\Particle\ReleasePreviewOpenOp;
use Splicewire\Beam\Ux\Routing\UxRoutes;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * owner-review-20261112 57: every beam-ux route a host still answers only at a legacy address has a twin at the
 * package root. The legacy side is the flagship's own composition, rebuilt here in its three auth contexts: the central
 * bearer group (`api/v1`, `api` + `auth:sanctum`), the central session group (`api/`, `auth`) and the tenant group
 * (`api/v1/tenant`, the Hosted Site entitlement and `site.edit`). Each twin is mounted under the SAME middleware, so a
 * twin must answer with the legacy route's action, middleware and particle defaults (resource, op, realm, ability
 * override). Central bearer twins live in their own `api/beam/ux` group with `beam.ux.api.` names (Q57-1), because the
 * session `beam/ux` group already answers the same method and URI.
 */
class UxRoutesTwinsTest extends TestCase
{
    private const BEARER = ['api', 'auth:sanctum'];

    private const SESSION = ['auth'];

    private const TENANT = ['tenant.boundary', 'auth:sanctum', 'site.entitlement', 'can:site.edit'];

    private const ENGINE = ['consume.engine'];

    private const TENANT_OPTIONS = [
        'realm' => 'tenant',
        'abilityOverrides' => ['ux.author' => 'site.edit'],
        'includeReleaseFiles' => false,
        'redirectsUri' => 'tenant/beam-ux-redirects',
    ];

    /** legacy method and URI => [twin URI, twin route name]; PLAN.md's 15 owner-root rows, then its 9 bearer rows. */
    private const TWINS = [
        // The 15 owner-root rows. The central `api/v1` rows are bearer, so their twins are in the bearer group.
        'GET api/v1/beam-ux-site-map/discovery' => ['api/beam/ux/beam-ux-site-map/discovery', 'beam.ux.api.beam-ux-site-map.discovery'],
        'GET api/v1/tenant/beam-ux-site-map/discovery' => ['beam/ux/tenant/beam-ux-site-map/discovery', 'beam.ux.tenant.beam-ux-site-map.discovery'],
        'GET api/v1/beam-ux-entries/hooks/events' => ['api/beam/ux/beam-ux-entries/hooks/events', 'beam.ux.api.beam-ux-entry.hooks.events'],
        'GET api/v1/beam-ux-entries' => ['api/beam/ux/beam-ux-entries', 'beam.ux.api.beam-ux-entry.index'],
        'POST api/v1/beam-ux-entries/{id}/refine-node' => ['api/beam/ux/beam-ux-entries/{id}/refine-node', 'beam.ux.api.beam-ux-entry.refine-node'],
        'GET api/v1/beam-ux-redirects/traces' => ['api/beam/ux/beam-ux-redirects/traces', 'beam.ux.api.beam-ux-redirect.traces'],
        'GET api/v1/beam-ux-site-map/hooks/events' => ['api/beam/ux/beam-ux-site-map/hooks/events', 'beam.ux.api.beam-ux-site-map.hooks.events'],
        'GET api/v1/beam-ux-site-map' => ['api/beam/ux/beam-ux-site-map', 'beam.ux.api.beam-ux-site-map.index'],
        // The unversioned row is the SESSION mount (routes/web.php's `auth` + `api/` group), so its twin is too.
        'POST api/beam-ux-entries/{id}/refine-node' => ['beam/ux/beam-ux-entries/{id}/refine-node', 'beam.ux.beam-ux-entry.refine-node'],
        'GET api/v1/tenant/beam-ux-entries/hooks/events' => ['beam/ux/tenant/beam-ux-entries/hooks/events', 'beam.ux.tenant.beam-ux-entry.hooks.events'],
        'GET api/v1/tenant/beam-ux-entries' => ['beam/ux/tenant/beam-ux-entries', 'beam.ux.tenant.beam-ux-entry.index'],
        'GET api/v1/tenant/beam-ux-site-map/hooks/events' => ['beam/ux/tenant/beam-ux-site-map/hooks/events', 'beam.ux.tenant.beam-ux-site-map.hooks.events'],
        'GET api/v1/tenant/beam-ux-site-map' => ['beam/ux/tenant/beam-ux-site-map', 'beam.ux.tenant.beam-ux-site-map.index'],
        'GET api/v1/tenant/beam-ux-redirects/traces' => ['beam/ux/tenant/beam-ux-redirects/traces', 'beam.ux.tenant.beam-ux-redirect.traces'],
        'POST api/v1/tenant/beam-ux-entries/{id}/refine-node' => ['beam/ux/tenant/beam-ux-entries/{id}/refine-node', 'beam.ux.tenant.beam-ux-entry.refine-node'],
        // The 9 central bearer rows: the session `beam/ux` routes of the same action are not bearer twins.
        'GET api/v1/beam-ux-entries/discovery' => ['api/beam/ux/beam-ux-entries/discovery', 'beam.ux.api.beam-ux-entry.discovery'],
        'GET api/v1/beam-ux-entries/{id}/body' => ['api/beam/ux/beam-ux-entries/{id}/body', 'beam.ux.api.beam-ux-entry.body'],
        'POST api/v1/beam-ux-entries/{id}/clear-body' => ['api/beam/ux/beam-ux-entries/{id}/clear-body', 'beam.ux.api.beam-ux-entry.clear-body'],
        'POST api/v1/beam-ux-entries/{id}/publish' => ['api/beam/ux/beam-ux-entries/{id}/publish', 'beam.ux.api.beam-ux-entry.publish'],
        'POST api/v1/beam-ux-entries/{id}/restore' => ['api/beam/ux/beam-ux-entries/{id}/restore', 'beam.ux.api.beam-ux-entry.restore'],
        'POST api/v1/beam-ux-entries/{id}/save-body' => ['api/beam/ux/beam-ux-entries/{id}/save-body', 'beam.ux.api.beam-ux-entry.save-body'],
        'POST api/v1/beam-ux-entries/{id}/save-draft' => ['api/beam/ux/beam-ux-entries/{id}/save-draft', 'beam.ux.api.beam-ux-entry.save-draft'],
        'GET api/v1/beam-ux-entries/{id}/versions' => ['api/beam/ux/beam-ux-entries/{id}/versions', 'beam.ux.api.beam-ux-entry.versions'],
        'GET api/v1/beam-ux-redirects/discovery' => ['api/beam/ux/beam-ux-redirects/discovery', 'beam.ux.api.beam-ux-redirect.discovery'],
    ];

    /** The particle defaults a twin must carry exactly as its legacy route does. */
    private const DEFAULTS = [
        ParticleController::RESOURCE,
        ParticleOperationController::RESOURCE,
        ParticleOperationController::REALM,
        ParticleOperationController::ABILITY_OVERRIDES,
    ];

    public function test_every_legacy_only_route_has_a_twin_at_the_package_root_in_its_own_auth_context(): void
    {
        $this->legacy();
        $this->twins();
        app(ResourceDiscoveryAutoMounter::class)->mount();

        $routes = app(Router::class)->getRoutes();
        $routes->refreshNameLookups();
        $rows = [];
        foreach (self::TWINS as $from => [$uri, $name]) {
            [$method, $legacyUri] = explode(' ', $from, 2);
            $legacy = $this->route($method, $legacyUri);
            $this->assertNotNull($legacy, "legacy {$from} is not in the rebuilt host composition");

            $twin = $routes->getByName($name);
            $rows[$from] = $twin === null ? null : [
                'uri' => $twin->uri(),
                'methods' => $twin->methods(),
                'action' => $twin->getActionName(),
                'middleware' => $twin->gatherMiddleware(),
                'defaults' => $this->particleDefaults($twin),
            ];
            $expected = [
                'uri' => $uri,
                'methods' => $legacy->methods(),
                'action' => $legacy->getActionName(),
                'middleware' => $legacy->gatherMiddleware(),
                'defaults' => $this->particleDefaults($legacy),
            ];
            $this->assertSame($expected, $rows[$from], "twin of {$from} ({$name})");
        }

        $this->assertCount(24, array_filter($rows));
    }

    public function test_the_subject_free_traces_op_is_not_captured_by_the_redirect_show_route(): void
    {
        $this->twins();

        foreach (['beam/ux/tenant/beam-ux-redirects/traces' => 'beam.ux.tenant.beam-ux-redirect.traces', 'api/beam/ux/beam-ux-redirects/traces' => 'beam.ux.api.beam-ux-redirect.traces'] as $path => $name) {
            $this->assertSame($name, app(Router::class)->getRoutes()->match(Request::create('/'.$path))->getName(), $path);
        }
    }

    public function test_the_bearer_group_moves_with_the_configured_root(): void
    {
        config(['beam.ux.api_root' => 'custom/ux']);
        Route::middleware(self::BEARER)->group(fn () => UxRoutes::bearer(self::ENGINE));
        app(Router::class)->getRoutes()->refreshNameLookups();

        $this->assertSame('api/custom/ux/beam-ux-entries', app(Router::class)->getRoutes()->getByName('beam.ux.api.beam-ux-entry.index')?->uri());
    }

    /** The flagship's three legacy compositions at app main (routes/api.php, routes/web.php, routes/tenant.php). */
    private function legacy(): void
    {
        $this->registerDeclarations();

        Route::middleware(self::BEARER)->prefix('api/v1')->name('api.v1.')->group(function () {
            // tower's TowerStudioApiRoutes::uxBuilderEntries()
            Particle::mount('beam-ux-entries', 'beam-ux-entry')->only(['index'])->idConstraint('uuid');
            Particle::ops('beam-ux-entries', 'beam-ux-entry', ['body', 'save-body', 'clear-body', 'save-draft', 'publish', 'versions', 'restore']);
            EditorRoutes::nodeActions('beam-ux-entries', self::ENGINE);
            Particle::ops('beam-ux-redirects', 'beam-ux-redirect', ['traces']);
            Particle::mount('beam-ux-site-map', 'beam-ux-site-map')->only(['index'])->idConstraint('uuid');
        });

        Route::middleware(self::SESSION)->prefix('api')->group(function () {
            EditorRoutes::mount();
            EditorRoutes::nodeActions('beam-ux-entries', self::ENGINE);
        });

        Route::middleware(self::TENANT)->prefix('api/v1')->name('v1.')->group(function () {
            Particle::mount('tenant/beam-ux-entries', 'beam-ux-entry')->only(['index'])->idConstraint('uuid')->names('tenant.beam-ux-entry')->register();
            Particle::mount('tenant/beam-ux-site-map', 'beam-ux-site-map')->only(['index'])->idConstraint('uuid')->names('tenant.beam-ux-site-map')->register();
            Particle::ops('tenant/beam-ux-redirects', 'beam-ux-redirect', ['traces'], [
                'names' => 'tenant.beam-ux-redirect',
                'realm' => 'tenant',
                'abilityOverrides' => ['ux.author' => 'site.edit'],
            ]);
            foreach (Route::getRoutes() as $route) {
                if (in_array($route->getName(), ['v1.tenant.beam-ux-entry.index', 'v1.tenant.beam-ux-site-map.index'], true)) {
                    $route->defaults(ParticleOperationController::REALM, 'tenant');
                    $route->defaults(ParticleOperationController::ABILITY_OVERRIDES, ['ux.author' => 'site.edit']);
                }
            }
            EditorRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', self::TENANT_OPTIONS);
            EditorRoutes::nodeActions('tenant/beam-ux-entries', self::ENGINE, ['realm' => 'tenant', 'abilityOverrides' => ['ux.author' => 'site.edit']]);
        });
    }

    /** What the host will call at the package root, each under the same middleware as its legacy group. */
    private function twins(): void
    {
        $this->registerDeclarations();

        Route::middleware(self::BEARER)->group(fn () => UxRoutes::bearer(self::ENGINE));

        Route::middleware(self::SESSION)->group(function () {
            UxRoutes::mount();
            UxRoutes::nodeActions(self::ENGINE);
        });

        Route::middleware(self::TENANT)->group(function () {
            UxRoutes::siteSection('tenant/', self::TENANT_OPTIONS);
            UxRoutes::mount('tenant/beam-ux-entries', 'tenant/beam-ux-releases', self::TENANT_OPTIONS);
            UxRoutes::nodeActions(self::ENGINE, 'tenant/beam-ux-entries', self::TENANT_OPTIONS);
        });
    }

    private function registerDeclarations(): void
    {
        foreach ([ReleasePreviewOpenOp::class, RedirectTracesOp::class, SiteMapRowData::class] as $class) {
            app(AttributedParticleDiscovery::class)->registerClass($class);
        }
    }

    private function route(string $method, string $uri): ?RouteInstance
    {
        foreach (app(Router::class)->getRoutes()->get($method) as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function particleDefaults(RouteInstance $route): array
    {
        return array_intersect_key($route->defaults, array_flip(self::DEFAULTS));
    }
}
