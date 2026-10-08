<?php

namespace Splicewire\Beam\Ux\Ia\Invariants;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Ia\NavInvariant;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** **I6**: every local nav seat resolves exactly one authorization decision from its route. */
class SeatResolvesOneGate implements NavInvariant
{
    public function __construct(
        protected SeatGate $gates,
        protected Router $router,
    ) {}

    public function id(): string
    {
        return 'I6';
    }

    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string
    {
        $path = IaInvariants::internalPath($node['href'] ?? null);

        if ($path === null || IaInvariants::isGroupHeader($node)) {
            return null;
        }

        $routeName = $node['routeName'] ?? null;

        if (is_string($routeName) && $routeName !== '') {
            if ($this->gates->resolve($routeName, $realm) !== null) {
                return null;
            }

            if ($this->namedRoute($routeName) !== null) {
                return "route [{$routeName}] declares no resolvable seat gate";
            }
        }

        $route = $this->routeFor($path);

        // I4 owns an href with no local route, a fallback-only match, or another realm's entry route.
        // I6 starts only once there is a real local route whose authorization declaration can be read.
        if ($route === null || $route->isFallback || $this->belongsToAnotherRealm($route, $realm)) {
            return null;
        }

        return $this->gates->resolveRoute($route, $realm) === null
            ? sprintf('route [%s] declares no resolvable seat gate', $route->getName() ?? $path)
            : null;
    }

    protected function namedRoute(string $name): ?Route
    {
        $routes = $this->router->getRoutes();
        $route = $routes->getByName($name);

        if ($route instanceof Route) {
            return $route;
        }

        return collect($routes->getRoutes())->first(
            fn (Route $candidate): bool => $candidate->getName() === $name,
        );
    }

    protected function routeFor(string $path): ?Route
    {
        $root = app()->bound('request') ? app('request')->getSchemeAndHttpHost() : '';

        try {
            return $this->router->getRoutes()->match(Request::create($root.$path, 'GET'));
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    protected function belongsToAnotherRealm(Route $route, string $realm): bool
    {
        $mount = $route->defaults['beamUxRealm'] ?? null;

        return is_string($mount) && $mount !== $realm;
    }
}
