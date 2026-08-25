<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Http\Controllers\EntryArtifactController;
use Splicewire\Beam\Ux\Http\Controllers\PublicEntryController;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Http\InertiaEntryRenderer;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * The PUBLIC entry surface: the container bindings behind it, and the `beamUxPublicEntries()` route macro that mounts it.
 *
 * ⚠️ Contributes to BOTH chains — `register` for the bindings, `boot` for the macro — which is why the
 * concern is one trait rather than two. The naming convention (`boot{TraitBasename}`) structurally
 * cannot express that; the attribute can.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresPublicSurface
{
    /**
     * The **public serving** seam (ADR-0209) — resolution, the uniform-404 gate triple, and the response
     * port. Bound here rather than in the macro so a host can resolve any of them directly (a sitemap
     * warmer, a preview route of its own) without mounting the renderer, and so a headless install pays
     * nothing: nothing in this method touches the router.
     *
     * {@see EntryRenderer} is a port because beam-ux does not require Inertia (§6, and it is not in
     * `composer.json`); the shipped binding is the Inertia one, and a non-Inertia host rebinds it.
     */
    #[Chained('register', order: 30)]
    protected function registerPublic(): void
    {
        $this->app->singleton(EntryPathResolver::class, fn () => new EntryPathResolver);

        $this->app->singleton(PublicEntryGate::class, fn ($app) => new PublicEntryGate(
            $app->make(EntryPathResolver::class),
            $app->make(EntryAccessResolver::class),
            $app->make(EntryPublishGate::class),
        ));

        $this->app->bind(EntryRenderer::class, InertiaEntryRenderer::class);
    }

    /**
     * Register the `Route::beamUxSite()` macro — the **host-mounted** public renderer (ADR-0209 §2).
     *
     *   Route::beamUxSite('site/entry')  →  GET {artifactRoot}/{entry} → artifact  (beam.ux.site.artifact)
     *                                       GET {path}                 → show      (beam.ux.site.show)
     *
     * The host calls this as the LAST line of its `web.php`. Laravel matches in registration order, so a
     * catch-all registered last shadows nothing the host already claimed — but only the host can
     * guarantee that ordering, and a package silently claiming every unmatched URL in an application is
     * a day of debugging. A host that never calls the macro gets no public surface at all, which is what
     * keeps beam-ux headless-installable without inventing a package boundary to protect it.
     *
     * **Last line of `web.php` is not last registered.** `$reservedPrefixes` exists because that premise
     * is false on any host with deferred route registration: stancl/tenancy groups `routes/tenant.php`
     * inside `$this->app->booted()`, and a host's own route closure may mount more groups after
     * `web.php`. Both register AFTER this catch-all and lose to it by construction. The renderer then
     * runs, resolves nothing, and returns its uniform 404 — which reads as "the API is broken", not as
     * "a catch-all shadowed it", because the route IS registered and `route:list` prints it in a
     * different order than the one that serves requests. Defaults to `['api']` from
     * `beam.ux.site.reserved_prefixes`; the constraint is on the route, because Laravel has no "next
     * route" and a controller that aborts has already swallowed the URL.
     *
     * `$claimRoot` is **off by default**: the direction of travel is a whole site served from entries,
     * but installing beam-ux must never take a host's homepage. `$page` is required and un-defaulted
     * because no beam package ships a rendered page (§6) — the component name is the host's.
     *
     * Middleware stays the host's: a private site mounts this inside its own `auth` group and every
     * entry inherits it (§4). The artifact route is registered FIRST so the catch-all cannot swallow it.
     */
    #[Chained('boot', order: 50)]
    protected function bootPublicRouteMacro(): void
    {
        if (Route::hasMacro('beamUxSite')) {
            return;
        }

        Route::macro('beamUxSite', function (
            string $page,
            string $realm = BeamUxEntry::REALM_SITE,
            bool $claimRoot = false,
            bool $withNav = true,
            ?string $artifactRoot = null,
            ?string $routeName = null,
            ?array $reservedPrefixes = null,
        ): void {
            /** @var Router $this */
            $artifactRoot ??= config('beam.ux.site.artifact_root', 'beam/ux/artifacts');
            $routeName ??= config('beam.ux.route_name', 'beam.ux.');
            $reservedPrefixes ??= config('beam.ux.site.reserved_prefixes', ['api']);

            $artifactName = "{$routeName}site.artifact";

            $defaults = [
                'beamUxRealm' => $realm,
                'beamUxPage' => $page,
                'beamUxNav' => $withNav,
                'beamUxArtifactRoute' => $artifactName,
            ];

            $apply = function ($route) use ($defaults) {
                foreach ($defaults as $key => $value) {
                    $route->defaults($key, $value);
                }

                return $route;
            };

            // `{version?}` is what earns the immutable cache header (ADR-0209 §7). Without it the
            // artifact sat at a FIXED address served `immutable, max-age=1y`, so a browser that had
            // loaded a page once never asked again and no body edit ever reached a returning reader.
            $apply($this->get("{$artifactRoot}/{entry}/{version?}", EntryArtifactController::class)
                ->name($artifactName));

            if ($claimRoot) {
                $apply($this->get('/', PublicEntryController::class)
                    ->name("{$routeName}site.root"));
            }

            $apply($this->get('{path}', PublicEntryController::class)
                ->where('path', PublicEntryController::pathConstraint($reservedPrefixes))
                ->name("{$routeName}site.show"));
        });
    }
}
