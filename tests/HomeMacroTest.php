<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Splicewire\Beam\Ux\Http\Controllers\HomeEntryController;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;

class HomeMacroTest extends TestCase
{
    public function test_the_home_is_opt_in_and_mounts_a_cacheable_controller_with_its_arguments(): void
    {
        $router = $this->router();
        $this->assertCount(0, $router->getRoutes());
        $route = $router->beamUxHome('front', slug: 'front', realm: 'public', name: 'front');
        $this->assertSame(HomeEntryController::class, $route->getActionName());
        $this->assertSame('/', $route->uri());
        $this->assertSame('front', $route->getName());
        $this->assertSame([ResolvePreviewContext::class], $route->middleware());
        $this->assertSame(['beamUxHomePage' => 'front', 'beamUxHomeSlug' => 'front', 'beamUxHomeRealm' => 'public'], $route->defaults);
    }

    public function test_home_refuses_a_named_existing_get_at_the_same_root(): void
    {
        $router = $this->router();
        $router->get('/', fn () => 'old')->name('old.home');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route::beamUxHome(): GET / is already registered (old.home).');
        $router->beamUxHome('site/home');
    }

    public function test_site_claim_root_refuses_the_existing_home(): void
    {
        $router = $this->router();
        $router->beamUxHome('site/home');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route::beamUxSite(claimRoot: true): GET / is already registered (home).');
        $router->beamUxSite('site/entry', claimRoot: true);
    }

    public function test_home_refuses_an_existing_site_catch_all_by_name(): void
    {
        $router = $this->router();
        $router->beamUxSite('site/entry');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamUxHome(): Route::beamUxSite() is already registered (beam.ux.site.show, '{path}')");
        $router->beamUxHome('site/home');
    }

    public function test_an_undomained_home_overlaps_an_earlier_domain_specific_site(): void
    {
        $router = $this->router();
        $router->domain('one.invalid')->group(fn () => $router->beamUxSite('site/entry', routeName: 'one.'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Route::beamUxHome(): Route::beamUxSite() is already registered (one.site.show, '{path}')");
        $router->beamUxHome('site/home');
    }

    public function test_distinct_domain_roots_can_coexist_as_on_the_flagship(): void
    {
        $router = $this->router();
        $router->domain('one.invalid')->group(fn () => $router->beamUxHome('one', name: 'one.home'));
        $router->domain('two.invalid')->group(fn () => $router->beamUxSite('two', claimRoot: true, routeName: 'two.'));
        $router->domain('three.invalid')->group(fn () => $router->beamUxHome('three', name: 'three.home'));
        foreach (['one' => 'one.home', 'two' => 'two.site.root', 'three' => 'three.home'] as $host => $name) {
            $this->assertSame($name, $router->getRoutes()->match(Request::create("https://{$host}.invalid/"))->getName());
        }
    }

    public function test_distinct_prefix_roots_can_coexist(): void
    {
        $router = $this->router();
        $router->prefix('one')->group(fn () => $router->beamUxHome('one', name: 'one.home'));
        $router->prefix('two')->group(fn () => $router->beamUxSite('two', claimRoot: true, routeName: 'two.'));
        $this->assertSame('one.home', $router->getRoutes()->match(Request::create('/one'))->getName());
        $this->assertSame('two.site.root', $router->getRoutes()->match(Request::create('/two'))->getName());
    }

    private function router(): Router
    {
        return new Router($this->app['events'], $this->app);
    }
}
