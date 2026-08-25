<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresEntryRoutes
{
    /**
     * Register the `Route::beamUxEntries()` macro — the declarative mount for the entry-body transport
     * ({@see BeamUxEntryBodyController}), so a host stops hand-rolling the `beam/ux/entries/{slug}/body`
     * load/save routes app-locally (they were promoted here from splicewire-app, beam-native-controllers P4).
     *
     *   Route::beamUxEntries()  →  GET  beam/ux/entries/{slug}/body → show   (beam.ux.entries.body.show)
     *                              PUT  beam/ux/entries/{slug}/body → update (beam.ux.entries.body.update)
     *
     * The "what did the disk mirror do" READ question (mirror-status-ui ticket 01) used to ride a third
     * route here too; it's now the generic `beam-ux-mirror-status` `#[ParticleResource]`
     * ({@see \Splicewire\Beam\Ux\Data\MirrorStatusRowData}) instead — no bespoke route, controller, or
     * envelope, per the particle doctrine (`docs/agents/particle-doctrine.md` in `laravel-beam`): a host
     * mounts it like any other resource, `Route::particleResource('beam/ux/mirror-status',
     * 'beam-ux-mirror-status', ['only' => ['index']])`, or leaves it REST-unmounted and lets Frame's
     * resource-blind Admin browser serve it off the registry alone.
     *
     * The uri prefix + name stem default from config (`beam.ux.api_root` / `beam.ux.route_name`,
     * env-overridable — ADR-0124), NOT hardcoded, so a host relocates the mount per-deploy without a code
     * change; explicit args still override. Routing (the enclosing middleware group) stays a HOST concern:
     * a host calls this inside its own auth/tenant-guarded group — the middleware IS the write gate (the
     * controller binds a permissive PolicyWriteGate).
     */
    #[Chained('boot', order: 40)]
    protected function bootRouteMacro(): void
    {
        if (Route::hasMacro('beamUxEntries')) {
            return;
        }

        Route::macro('beamUxEntries', function (
            ?string $apiRoot = null,
            ?string $routeName = null,
        ): void {
            /** @var Router $this */
            $apiRoot ??= config('beam.ux.api_root', 'beam/ux');
            $routeName ??= config('beam.ux.route_name', 'beam.ux.');

            $this->get("{$apiRoot}/entries/{slug}/body", [BeamUxEntryBodyController::class, 'show'])
                ->name("{$routeName}entries.body.show");
            $this->put("{$apiRoot}/entries/{slug}/body", [BeamUxEntryBodyController::class, 'update'])
                ->name("{$routeName}entries.body.update");
        });
    }
}
