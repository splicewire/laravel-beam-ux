<?php

namespace Splicewire\Beam\Ux\Routing;

use Illuminate\Routing\Router;
use InvalidArgumentException;

/** Registration-time checks shared by the public home and console mounts. */
class SiteCatchAll
{
    public static function refuseAfter(Router $router, string $mount): void
    {
        $groups = $router->getGroupStack();
        $group = $groups === [] ? [] : $groups[array_key_last($groups)];
        $domain = $group['domain'] ?? null;
        $prefix = trim((string) $router->getLastGroupPrefix(), '/');

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! array_key_exists('beamUxPage', $route->defaults) || ! str_ends_with($route->uri(), '{path}')) {
                continue;
            }
            // Only two distinct explicit domains are disjoint. Either undomained mount serves every domain.
            if ($domain !== null && $route->getDomain() !== null && $route->getDomain() !== $domain) {
                continue;
            }
            $sitePrefix = trim(substr($route->uri(), 0, -strlen('{path}')), '/');
            if ($sitePrefix !== '' && $prefix !== $sitePrefix && ! str_starts_with($prefix, $sitePrefix.'/')) {
                continue;
            }
            $name = $route->getName() ?? 'unnamed';
            $subject = str_starts_with($mount, 'Route::beamRealmConsole(') ? "this realm's" : "this mount's";
            throw new InvalidArgumentException("{$mount}: Route::beamUxSite() is already registered ({$name}, '{$route->uri()}'), and its catch-all would take {$subject} URLs. Call it before Route::beamUxSite().");
        }
    }

    /** Refuse a second GET at the current group's root without claiming other domains or prefixes. */
    public static function refuseRoot(Router $router, string $mount): void
    {
        $groups = $router->getGroupStack();
        $group = $groups === [] ? [] : $groups[array_key_last($groups)];
        $domain = $group['domain'] ?? null;
        $uri = trim((string) $router->getLastGroupPrefix(), '/') ?: '/';

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->uri() === $uri && $route->getDomain() === $domain) {
                $name = $route->getName() ?? 'unnamed';
                throw new InvalidArgumentException("{$mount}: GET / is already registered ({$name}).");
            }
        }
    }
}
