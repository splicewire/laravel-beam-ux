<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;

class EditorAuthorizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::define('entitlement:ux.author', fn ($user): bool => $user instanceof EditorCentralAuthor);
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
