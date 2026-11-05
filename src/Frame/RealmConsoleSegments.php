<?php

namespace Splicewire\Beam\Ux\Frame;

use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;

/**
 * The route-relative first segments a prefixed realm console serves: `Route::beamRealmConsole()`'s
 * `{frameRoute}` constraint (see {@see \Splicewire\Beam\Ux\Concerns\WiresRealmConsole}).
 *
 * Two sources, both read at route-registration time:
 *  - {@see RouteContextProjector::hrefs()}, every leaf the realm's manifest projects (the same call the
 *    manifest's own hrefs come from, so a resource added to the realm is mounted by adding it there);
 *  - every nav seat DECLARED for the realm ({@see NavSectionRegistry::for()}), not the seats some viewer is
 *    shown. Routes are registered once, with no actor, so a gated projection here would be a guest's nav.
 *
 * Each href is stripped of the realm base (`/operator/users` → `users`) and cut to its first segment; a seat
 * href outside the base (`/calendars`, declared for every realm) keeps its own first segment. A projector
 * failure returns `[]`, so the console mounts nothing rather than everything.
 */
final class RealmConsoleSegments
{
    /**
     * @param  string  $base  the realm's route base without slashes (`operator`, `exec`)
     * @return list<string>
     */
    public static function for(string $realm, string $base): array
    {
        $hrefs = rescue(function () use ($realm): array {
            $paths = array_values(app(RouteContextProjector::class)->hrefs($realm));

            $sections = array_map(
                fn (NavSection $seat): string => $seat->href,
                app(NavSectionRegistry::class)->for($realm)
            );

            return array_filter([...$paths, ...$sections]);
        }, [], false);

        $prefix = '/'.$base;

        return array_values(array_filter(array_unique(array_map(
            function (string $href) use ($prefix): string {
                $relative = $href === $prefix || str_starts_with($href, $prefix.'/')
                    ? substr($href, strlen($prefix))
                    : $href;

                return explode('/', ltrim($relative, '/'))[0];
            },
            $hrefs
        ))));
    }
}
