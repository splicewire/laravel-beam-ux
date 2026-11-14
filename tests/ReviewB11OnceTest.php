<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Inertia\PropsResolver;
use Inertia\ServiceProvider;
use Splicewire\Beam\Ux\Http\Concerns\SharesBeamShell;

class ReviewB11Carrier extends Middleware
{
    use SharesBeamShell {localization as public catalogBootstrap; }
}
class ReviewB11OnceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ServiceProvider::class];
    }

    public function test_sequential_context_keys_and_exclusion(): void
    {
        config(['beam.localization.client_catalog' => __DIR__.'/Fixtures/Localization/discovered-catalog.json', 'beam.localization.pseudo' => true]);
        $keys = [];
        $prior = null;
        foreach ([['one.test', 'en', null], ['two.test', 'en', null], ['two.test', 'en-XA', null], ['two.test', 'en-XA', 'operator']] as [$host,$locale,$realm]) {
            $request = Request::create('https://'.$host.'/'.($realm ?? ''));
            if ($realm !== null) {
                $route = new \Illuminate\Routing\Route('GET', '/{realm}', fn () => null);
                $route->bind($request);
                $request->setRouteResolver(fn () => $route);
            }
            $this->app->instance('request', $request);
            $this->app->setLocale($locale);
            $this->app->forgetScopedInstances();
            $shared = (new ReviewB11Carrier)->catalogBootstrap($request);
            $key = $shared['initial']->getKey();
            $keys[] = $key;
            $request->headers->set('X-Inertia', 'true');
            if ($prior !== null) {
                $request->headers->set('X-Inertia-Except-Once-Props', $prior);
            }
            [$props] = (new PropsResolver($request, 'Page'))->resolve(['localization' => $shared], []);
            $this->assertArrayHasKey('initial', $props['localization']);
            $initial = $props['localization']['initial'];
            $initial = is_array($initial) ? $initial : $initial->toArray();
            $this->assertSame($locale, $initial['locale']);
            $this->assertSame($realm, $initial['realm']);
            $request->headers->set('X-Inertia-Except-Once-Props', $key);
            [$again] = (new PropsResolver($request, 'Page'))->resolve(['localization' => $shared], []);
            $this->assertArrayNotHasKey('initial', $again['localization']);
            $this->assertArrayHasKey('address', $again['localization']);
            $prior = $key;
        }
        $this->assertCount(4,array_unique($keys));
    }
}
