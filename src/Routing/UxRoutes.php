<?php

namespace Splicewire\Beam\Ux\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Routing\ApiRoot;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * beam-ux's editor routes at the package's own root (ADR-0243, owner-review-20261112 13d): `beam.ux.api_root`,
 * default `beam/ux`, the `beam` tier. Until now that key only positioned beam core's Scribe include-list; the editor
 * surface mounted wherever the host called `EditorRoutes::mount()` (the flagship: `api/…`, outside its version
 * prefix). The host calls this inside its authentication boundary, beside the legacy call, which stays until ticket
 * 16. The package adds no middleware here: the host's group is the boundary. Route names gain the `beam.ux.` stem.
 *
 * What it mounts is exactly `EditorRoutes::mount()` with the same arguments: the beam-ux-entry operations, the
 * beam-ux-release resource and its ops, beam-ux-release-files (unless excluded), and beam-ux-redirect. The site-map
 * index and the redirect `traces` op a host composes itself with `Particle::mount/ops` are not part of that export.
 */
final class UxRoutes
{
    /**
     * @param  array<string, mixed>  $mountOptions  as `EditorRoutes::mount()`: realm, abilityOverrides,
     *                                              includeReleaseFiles, redirectsUri
     */
    public static function mount(
        string $uri = 'beam-ux-entries',
        string $releasesUri = 'beam-ux-releases',
        array $mountOptions = [],
    ): void {
        ApiRoot::mount(app(Router::class), 'beam.ux.api_root', 'ux', function (Router $router) use ($uri, $releasesUri, $mountOptions): void {
            $router->name('beam.ux.')->group(fn () => EditorRoutes::mount($uri, $releasesUri, $mountOptions));
        });
    }
}
