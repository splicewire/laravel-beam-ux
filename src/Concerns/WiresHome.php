<?php

namespace Splicewire\Beam\Ux\Concerns;

use Closure;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Http\Controllers\HomeEntryController;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Routing\SiteCatchAll;

/** Opt-in home route: installing beam-ux never takes the host's front door. */
trait WiresHome
{
    #[Chained('boot', order: 53)]
    protected function bootHomeMacro(): void
    {
        if (Route::hasMacro('beamUxHome')) {
            return;
        }

        $this->registerHomeMacro(function (
            string $page,
            string $slug = 'home',
            string $realm = BeamUxEntry::REALM_SITE,
            string $name = 'home',
        ): RouteInstance {
            SiteCatchAll::refuseAfter($this, 'Route::beamUxHome()');
            SiteCatchAll::refuseRoot($this, 'Route::beamUxHome()');

            return $this->get('/', HomeEntryController::class)
                ->middleware(ResolvePreviewContext::class)
                ->defaults('beamUxHomePage', $page)
                ->defaults('beamUxHomeSlug', $slug)
                ->defaults('beamUxHomeRealm', $realm)
                ->name($name);
        });
    }

    /**
     * Laravel binds route macro closures to the receiving router, including a host's grouped router.
     *
     * @param-closure-this Router $macro
     */
    private function registerHomeMacro(Closure $macro): void
    {
        Route::macro('beamUxHome', $macro);
    }
}
