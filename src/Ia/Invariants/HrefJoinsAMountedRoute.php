<?php

namespace Splicewire\Beam\Ux\Ia\Invariants;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Splicewire\Beam\Ux\Ia\IaInvariants;
use Splicewire\Beam\Ux\Ia\NavInvariant;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * **I4** (IA-6): every nav href under a registered `routeBase` joins to a mounted GET route, whether or not the node
 * carries a `routeName`. That is what makes "the row goes with the route" true for rows with no `routeName`.
 *
 * The join is the router's own match, on this request's host, so a route's `where` constraints and domain count. The
 * fallback route answers every path and therefore joins nothing, and neither does another realm's entry route (a
 * route whose `beamUxRealm` default names a different realm, such as the site's `{path}` at every beam host).
 */
class HrefJoinsAMountedRoute implements NavInvariant
{
    public function __construct(protected Router $router) {}

    public function id(): string
    {
        return 'I4';
    }

    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string
    {
        $path = IaInvariants::internalPath($node['href'] ?? null);
        if ($path === null || IaInvariants::isGroupHeader($node)) {
            return null;
        }

        $root = app()->bound('request') ? app('request')->getSchemeAndHttpHost() : '';

        try {
            $route = $this->router->getRoutes()->match(Request::create($root.$path, 'GET'));
        } catch (HttpExceptionInterface) {
            return 'matches no GET route';
        }

        if ($route->isFallback) {
            return 'matches only the fallback route';
        }

        // A realm's entry route (the public site's `{path}`) answers every path it is not beaten to, so for another
        // realm's rail it is no join: it would render a site page, or the site's 404, not the rail's surface.
        $mount = $route->defaults['beamUxRealm'] ?? null;

        return is_string($mount) && $mount !== $realm ? "is answered only by the {$mount} realm's entry route" : null;
    }
}
