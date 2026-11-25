<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavRegistry;
use Rushing\DataNav\NavTree;
use Schemastud\Frame\Realm\RealmDefinition;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\RealmResourceProjection;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\Frame\FrameNavContribution;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Splicewire\Beam\Ux\Frame\RouteContextValidator;

class FrameRealmProjectionTest extends TestCase
{
    public function test_route_context_and_navigation_share_one_realm_projection(): void
    {
        $resources = new class extends ParticleResourceRegistry
        {
            public int $projections = 0;

            public function definitions(?string $realm = null): array
            {
                $this->projections++;

                return [];
            }
        };
        $routes = $this->createStub(RouteContextProjector::class);
        $routeProjection = null;
        $routes->method('routeContext')->willReturnCallback(
            function (string $realm) use ($resources, &$routeProjection): array {
                $routeProjection = $resources->currentRealmProjection($realm);

                return [];
            },
        );
        $realms = $this->createStub(RealmRegistry::class);
        $realms->method('tryResolve')->willReturn($this->createStub(RealmDefinition::class));
        $contribution = new class(
            $routes, $this->createStub(RouteContextValidator::class),
            $this->createStub(NavRegistry::class), $realms, Request::create('/manifest'),
        ) extends FrameNavContribution
        {
            public ?RealmResourceProjection $navigationProjection = null;

            public NavTree $tree;

            protected function navigation(string $realm, ?NavContext $context = null): NavTree
            {
                $this->navigationProjection = app(ParticleResourceRegistry::class)->currentRealmProjection($realm);

                return $this->tree;
            }
        };

        $contribution->tree = $this->createStub(NavTree::class);

        $previous = Container::getInstance();
        $container = new Container;
        $container->instance(ParticleResourceRegistry::class, $resources);
        Container::setInstance($container);
        try {
            $contribution->contributeNav('tenant');
        } finally {
            Container::setInstance($previous);
        }

        self::assertSame(1, $resources->projections);
        self::assertNotNull($routeProjection);
        self::assertSame($routeProjection, $contribution->navigationProjection);
        self::assertNull($resources->currentRealmProjection('tenant'));
    }
}
