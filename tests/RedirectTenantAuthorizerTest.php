<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Data\RedirectInputData;
use Splicewire\Beam\Ux\Redirect\RedirectPolicy;
use Splicewire\Beam\Ux\Redirect\RedirectService;

class RedirectTenantAuthorizerTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        Gate::define('entitlement:site.edit', fn ($user): bool => $user instanceof TenantRedirectEditor);
        Gate::define('entitlement:ux.author', fn ($user): bool => $user instanceof CentralRedirectAuthor);
        Gate::define('ux.author', fn ($user): bool => $user instanceof CentralRedirectAuthor);
    }

    public function test_tenant_redirect_list_create_update_delete_use_site_edit_but_central_stays_ux_author(): void
    {
        $policy = new RedirectPolicy;
        $tenantRoute = $this->route('tenant');
        $this->swapRequest($this->request($tenantRoute));
        $tenantEditor = $this->user('tenant-editor');
        $centralAuthor = $this->user('central-author');
        $unprivileged = $this->user('no-permission');

        $this->assertTrue($policy->viewAny($tenantEditor));
        $this->assertTrue($policy->create($tenantEditor));
        $prepared = new BeamUxRedirect;
        app(RedirectService::class)->prepare($prepared, new RedirectInputData('/old-path', 'path', '/new-path'), $tenantEditor);
        $this->assertSame('/new-path', $prepared->target_path);
        $redirect = new BeamUxRedirect(['target_kind' => 'path']);
        $this->assertTrue($policy->view($tenantEditor, $redirect));
        $this->assertTrue($policy->update($tenantEditor, $redirect));
        $this->assertTrue($policy->delete($tenantEditor, $redirect));
        $this->assertFalse($policy->viewAny($unprivileged));
        $this->assertFalse($policy->create($unprivileged));
        try {
            app(RedirectService::class)->prepare(new BeamUxRedirect, new RedirectInputData('/denied', 'path', '/new-path'), $unprivileged);
            $this->fail('A member without site.edit must not prepare a tenant Redirect.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->swapRequest($this->request($this->route(null)));
        $this->assertTrue($policy->viewAny($centralAuthor));
        $this->assertTrue($policy->create($centralAuthor));
        app(RedirectService::class)->prepare(new BeamUxRedirect, new RedirectInputData('/central', 'path', '/new-path'), $centralAuthor);
        $this->assertFalse($policy->viewAny($tenantEditor));
    }

    public function test_tenant_site_edit_does_not_replace_the_configured_page_publish_ability(): void
    {
        $tenantEditor = $this->user('tenant-editor');
        $this->swapRequest($this->request($this->route('tenant')));
        $page = BeamUxEntry::create([
            'slug' => 'publish-check',
            'type' => 'page',
            'format' => 'tsx',
            'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id,
            'segment' => 'publish-check',
        ]);
        $redirect = new BeamUxRedirect(['target_kind' => 'page', 'target_entry_id' => (string) $page->getKey()]);
        $canPublish = false;
        Gate::define('page.publish', function () use (&$canPublish): bool {
            return $canPublish;
        });
        config(['beam.ux.releases.publish_ability' => 'page.publish']);

        $service = app(RedirectService::class);
        $this->assertTrue(app(\Splicewire\Beam\Ux\Support\EditorAuthorizer::class)->allows($tenantEditor));
        $this->assertFalse($service->mayManage($tenantEditor, $redirect));

        $canPublish = true;
        $this->assertTrue($service->mayManage($tenantEditor, $redirect));
    }

    private function route(?string $realm): Route
    {
        $route = new Route(['GET'], '/tenant/beam-ux-redirects', fn () => null);
        if ($realm !== null) {
            $route->defaults(ParticleOperationController::REALM, $realm);
            $route->defaults(ParticleOperationController::ABILITY_OVERRIDES, ['ux.author' => 'site.edit']);
        }

        return $route;
    }

    private function request(Route $route): Request
    {
        $request = Request::create('/tenant/beam-ux-redirects');
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function swapRequest(Request $request): void
    {
        $this->app->instance('request', $request);
        \Illuminate\Support\Facades\Request::swap($request);
    }

    private function user(string $id): User
    {
        return match ($id) {
            'tenant-editor' => new TenantRedirectEditor,
            'central-author' => new CentralRedirectAuthor,
            default => new User,
        };
    }
}

class TenantRedirectEditor extends User {}

class CentralRedirectAuthor extends User {}
