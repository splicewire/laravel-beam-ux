<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Rushing\Popcorn\Concerns\Chained;
use Schemastud\Frame\Http\Controllers\FrameManifestController;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Realm\RealmGateAbility;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Data\Pages\RealmConsolePageData;
use Splicewire\Beam\Ux\Frame\RealmConsoleSegments;
use Splicewire\Beam\Ux\Http\EntryRenderer;

/**
 * One concern of {@see BeamUxServiceProvider}: the `Route::beamRealmConsole()` macro, which mounts a second
 * realm's frame console with one host line.
 *
 *   Route::beamRealmConsole('exec', ['auth', 'verified']);
 *
 *     GET {base}                 → redirect to {base}/dashboard           ({realm}.home)
 *     GET {base}/frame/manifest  → FrameManifestController, realm default  ({realm}.frame.manifest)
 *     GET {base}/{frameRoute}    → the frame console page                  ({realm}.frame.console)
 *
 * It is the starters' hand-written operator mount, lifted: the same three routes, names, middleware and
 * `{frameRoute}` constraint, derived from the realm instead of spelled for `operator`.
 *
 *  - **The manifest** carries `->defaults('realm', $realm)`. That default is the whole mechanism:
 *    `NavManifest::realmFor()` reads it off the matched route, so the manifest projects this realm's nav and
 *    `routeContext` instead of the default realm's.
 *  - **The segments are derived, never written down** ({@see RealmConsoleSegments}): the realm's projected
 *    leaves plus every nav seat declared for it. The optional second segment matches the `/:id` twin. A
 *    projector failure mounts no console rather than an unconstrained catch-all.
 *  - **The door is the realm's gate.** The macro appends `can:{ability}`, where the ability is
 *    {@see RealmGateAbility::for()}: the same string the realm's resources and dashboard are gated on. A host
 *    cannot mount the console as a softer door than the dashboard behind it. A realm that gates nothing gets
 *    no `can:`. The frame socket behind the console is gated per resource on its own; this is the door.
 *  - **The page** renders through beam-ux's {@see EntryRenderer} port (Inertia by default), because beam-ux
 *    does not require Inertia. Its props are {@see RealmConsolePageData}. `$page` defaults to the packaged
 *    `frame/console` component every starter serves.
 *
 * **Call it before `Route::beamUxSite()`**, whose `{path}` catch-all would otherwise take the realm's URLs, and
 * from the route file, after providers boot: the realm and its dashboard leaf must be registered by then. A
 * realm a host provider registers in its own `boot()` gets its dashboard leaf too late for the route file (the
 * known limit {@see WiresRealmDashboards} documents), so register such a realm in `register()`.
 *
 * A realm mounted at `/` (tenant, site) is refused: its console is the host's root mount, not a prefixed one.
 */
trait WiresRealmConsole
{
    #[Chained('boot', order: 52)]
    protected function bootRealmConsoleMacro(): void
    {
        if (Route::hasMacro('beamRealmConsole')) {
            return;
        }

        Route::macro('beamRealmConsole', function (
            string $realm,
            array $middleware = ['auth', 'verified'],
            string $page = 'frame/console',
            bool $home = true,
        ): void {
            /** @var Router $this */
            $router = $this;
            $base = trim(app(RealmRegistry::class)->resolve($realm)->routeBase, '/');

            if ($base === '') {
                throw new InvalidArgumentException(
                    "Route::beamRealmConsole('{$realm}'): the realm is mounted at '/', so its console is the host's root "
                    .'mount, not a prefixed one. Mount it in the route file as the tenant console is.'
                );
            }

            $ability = RealmGateAbility::for($realm, app(RealmRegistry::class));

            if ($ability !== null && ! in_array("can:{$ability}", $middleware, true)) {
                $middleware[] = "can:{$ability}";
            }

            $router->middleware($middleware)->prefix($base)->group(function () use ($router, $realm, $base, $page, $home): void {
                if ($home) {
                    // The realm's front door lands on its dashboard leaf. Behind the same gate as the console it
                    // redirects into, so the door is never softer than the room.
                    $router->get('', fn () => redirect()->to('/'.$base.'/'.RealmDashboard::PATH))
                        ->name("{$realm}.home");
                }

                $router->name("{$realm}.frame.")->group(function () use ($router, $realm, $base, $page): void {
                    $router->get('frame/manifest', FrameManifestController::class)
                        ->defaults('realm', $realm)
                        ->name('manifest');

                    $segments = RealmConsoleSegments::for($realm, $base);

                    if ($segments === []) {
                        return;
                    }

                    $router->get('{frameRoute}', fn () => app(EntryRenderer::class)->render($page, RealmConsolePageData::from([
                        'realm' => $realm,
                        'basename' => '/'.$base,
                        'manifestUrl' => '/'.$base.'/frame/manifest',
                    ])->toArray()))
                        ->where('frameRoute', '('.implode('|', array_map(fn (string $s): string => preg_quote($s, '/'), $segments)).')(\/[^\/]+)?')
                        ->name('console');
                });
            });
        });
    }
}
