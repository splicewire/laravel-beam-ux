<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use ReflectionProperty;
use RuntimeException;
use Splicewire\Beam\Collections\CollectionBacking;
use Splicewire\Beam\Ux\Bindings\BindingRequestScope;

class BindingRequestScopeTest extends TestCase
{
    public function test_the_scope_preserves_the_visitor_and_re_resolves_backings_for_each_address(): void
    {
        $visitor = new User;
        $visitor->id = 7;
        $request = Request::create('/page');
        $request->setUserResolver(fn () => $visitor);
        $request->attributes->set('tenant', 'tenant-a');
        $original = app(Request::class);
        $cached = app(CollectionBacking::class);
        $this->app->instance(CollectionBacking::class, $cached);
        $seen = [];
        foreach (['https://example.test/first', 'https://example.test/second'] as $address) {
            app(BindingRequestScope::class)->run($request, ['collection' => $address], function () use (&$seen, $visitor, $address, $cached): void {
                $inside = app(Request::class);
                $this->assertSame($visitor, $inside->user());
                $this->assertSame('tenant-a', $inside->attributes->get('tenant'));
                $this->assertSame($address, $inside->query('collection'));
                $backing = app(CollectionBacking::class);
                $this->assertNotSame($cached, $backing);
                $this->assertSame($inside, (new ReflectionProperty($backing, 'request'))->getValue($backing));
                $seen[] = $backing;
            });
        }
        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertSame($original, app(Request::class));
        $this->assertSame($cached, app(CollectionBacking::class));
        $this->assertNull($request->query('collection'));
    }

    public function test_a_throw_restores_request_and_backing_before_the_next_scope(): void
    {
        $original = app(Request::class);
        $guest = Request::create('/page');
        $guest->setUserResolver(fn () => null);
        try {
            app(BindingRequestScope::class)->run($guest, ['collection' => 'first'], function (): void {
                $this->assertNull(app(Request::class)->user());
                throw new RuntimeException('failed read');
            });
            $this->fail('exception swallowed');
        } catch (RuntimeException $error) {
            $this->assertSame('failed read', $error->getMessage());
        }
        $this->assertSame($original, app(Request::class));
        $next = app(BindingRequestScope::class)->run($guest, ['collection' => 'second'], fn () => app(Request::class)->query('collection'));
        $this->assertSame('second', $next);
        $this->assertSame($original, app(Request::class));
    }
}
