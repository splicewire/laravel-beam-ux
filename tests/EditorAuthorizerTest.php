<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Redirect\RedirectPolicy;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;

class EditorAuthorizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A central or route-less request must stay on the raw legacy Gate ability,
        // not the entitlement-prefixed ability used for a tenant override.
        Gate::define('ux.author', fn ($user): bool => $user instanceof EditorCentralAuthor);
        Gate::define('entitlement:ux.author', fn ($user): bool => false);
        Gate::define('entitlement:site.edit', fn ($user): bool => $user instanceof EditorTenantAuthor);
    }

    public function test_only_the_matched_tenant_route_can_map_authoring_to_site_edit(): void
    {
        $tenantRoute = $this->route('tenant', ['ux.author' => 'site.edit']);
        $strictTenantRoute = $this->route('tenant', []);
        $centralRoute = $this->route(null, ['ux.author' => 'site.edit']);
        $authorizer = app(EditorAuthorizer::class);

        $this->assertTrue($authorizer->allows(new EditorTenantAuthor, $this->request($tenantRoute)));
        $this->assertFalse($authorizer->allows(new EditorCentralAuthor, $this->request($tenantRoute)));
        $this->assertFalse($authorizer->allows(new EditorTenantAuthor, $this->request($strictTenantRoute)));
        $this->assertTrue($authorizer->allows(new EditorCentralAuthor, $this->request($strictTenantRoute)));
        $this->assertFalse($authorizer->allows(new EditorTenantAuthor, $this->request($centralRoute)));
        $this->assertTrue($authorizer->allows(new EditorCentralAuthor, $this->request($centralRoute)));
    }

    public function test_redirect_list_and_create_follow_the_matched_tenant_author_map_only(): void
    {
        Gate::define('ux.author', fn ($user): bool => $user instanceof EditorCentralAuthor);
        $policy = new RedirectPolicy;
        $tenantRoute = $this->route('tenant', ['ux.author' => 'site.edit']);
        $centralRoute = $this->route(null, []);

        $this->app->instance('request', $this->request($tenantRoute));
        $this->assertTrue($policy->viewAny(new EditorTenantAuthor));
        $this->assertTrue($policy->create(new EditorTenantAuthor));
        $this->assertFalse($policy->viewAny(new EditorCentralAuthor));
        $this->assertFalse($policy->viewAny(new User));

        $this->app->instance('request', $this->request($centralRoute));
        $this->assertTrue($policy->viewAny(new EditorCentralAuthor));
        $this->assertFalse($policy->viewAny(new EditorTenantAuthor));
    }

    public function test_route_less_console_context_uses_the_plain_central_ux_author_gate(): void
    {
        $request = Request::create('/console');
        $request->setRouteResolver(fn () => null);
        $this->app->instance('request', $request);
        \Illuminate\Support\Facades\Request::swap($request);

        $authorizer = app(EditorAuthorizer::class);
        $this->assertTrue($authorizer->allows(new EditorCentralAuthor));
        $this->assertFalse($authorizer->allows(new EditorTenantAuthor));
    }

    public function test_central_authorization_keeps_the_legacy_gate_and_exception(): void
    {
        $authorizer = app(EditorAuthorizer::class);
        $centralRoute = $this->route(null, ['ux.author' => 'site.edit']);

        $authorizer->authorize(new EditorCentralAuthor, $this->request($centralRoute));

        $this->expectException(AuthorizationException::class);
        $authorizer->authorize(new User, $this->request($centralRoute));
    }

    private function route(?string $realm, array $overrides): Route
    {
        $route = new Route(['GET'], '/editor', fn () => null);
        if ($realm !== null) {
            $route->defaults(ParticleOperationController::REALM, $realm);
        }
        $route->defaults(ParticleOperationController::ABILITY_OVERRIDES, $overrides);

        return $route;
    }

    private function request(Route $route): Request
    {
        $request = Request::create('/editor');
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}

class EditorCentralAuthor extends User {}

class EditorTenantAuthor extends User {}
