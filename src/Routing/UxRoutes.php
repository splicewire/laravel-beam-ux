<?php

namespace Splicewire\Beam\Ux\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Routing\ApiRoot;
use Splicewire\Beam\Ux\Support\EditorRoutes;

/**
 * beam-ux's editor routes at the package's own root (ADR-0243, owner-review-20261112 13d): `beam.ux.api_root`,
 * default `beam/ux`, the `beam` tier. Until now that key only positioned beam core's Scribe include-list; the editor
 * surface mounted wherever the host called `EditorRoutes::mount()` (the flagship: `api/…`, outside its version
 * prefix). The host calls this inside its authentication boundary, beside the legacy call, which stays until ticket
 * 16. The package adds no middleware here: the host's group is the boundary. Route names gain the `beam.ux.` stem.
 *
 * `mount()` is exactly `EditorRoutes::mount()` with the same arguments: the beam-ux-entry operations, the
 * beam-ux-release resource and its ops, beam-ux-release-files (unless excluded), and beam-ux-redirect. Beside it
 * (owner-review-20261112 57): `siteSection()` (the entry and site-map indexes and the redirect `traces` op, which a
 * host used to compose itself with `Particle::mount/ops`), `nodeActions()` (the spend-capable `refine-node`) and
 * `bearer()`, the central editor surface a bearer client reads.
 */
final class UxRoutes
{
    /** The bearer group's name stem; it differs from the session group's because both mount the same resources. */
    private const BEARER_NAMES = 'beam.ux.api.';

    /** The ops tower's `uxBuilderEntries()` exposes to Studio's bearer client beside the entry index. */
    private const BEARER_ENTRY_OPS = ['body', 'save-body', 'clear-body', 'save-draft', 'publish', 'versions', 'restore'];

    /**
     * @param  array<string, mixed>  $mountOptions  as `EditorRoutes::mount()`: realm, abilityOverrides,
     *                                              includeReleaseFiles, redirectsUri
     */
    public static function mount(
        string $uri = 'beam-ux-entries',
        string $releasesUri = 'beam-ux-releases',
        array $mountOptions = [],
    ): void {
        self::atRoot('beam.ux.', fn () => EditorRoutes::mount($uri, $releasesUri, $mountOptions));
    }

    /**
     * The Site section's reads: the entry index, the site-map index and the redirect `traces` op, each under
     * `{$prefix}beam-ux-*`. `traces` is subject-free, so call this BEFORE `mount()` with the same prefix: the first
     * registered route wins, and `mount()`'s unconstrained `beam-ux-redirects/{id}` would otherwise answer `show` with
     * the id "traces". With a `realm` option the routes are named under it and carry the realm and `abilityOverrides`,
     * as the realm's editor routes do.
     *
     * @param  array<string, mixed>  $mountOptions  realm, abilityOverrides
     */
    public static function siteSection(string $prefix = '', array $mountOptions = []): void
    {
        self::atRoot('beam.ux.', fn () => self::siteSectionRoutes($prefix, $mountOptions));
    }

    /**
     * The spend-capable canvas node preview (`refine-node`) at the package root. The host supplies its
     * engine-consumption gate, as for `EditorRoutes::nodeActions()`.
     *
     * @param  list<string>  $engineConsumption
     * @param  array<string, mixed>  $mountOptions  realm, abilityOverrides
     */
    public static function nodeActions(array $engineConsumption, string $uri = 'beam-ux-entries', array $mountOptions = []): void
    {
        self::atRoot('beam.ux.', fn () => EditorRoutes::nodeActions($uri, $engineConsumption, $mountOptions));
    }

    /**
     * The central editor surface a bearer client reads (Studio's Site section and UX builder), at `api/{root}` with
     * `beam.ux.api.` names: the Site section's reads, the entry body ops and `refine-node`. Its own URI and name stem
     * keep it apart from the session group `mount()` serves at `{root}`, which answers some of the same methods and
     * paths (Q57-1). The host wraps it in its bearer boundary (`api` + `auth:sanctum`); the package adds none.
     *
     * @param  list<string>  $engineConsumption
     */
    public static function bearer(array $engineConsumption): void
    {
        app(Router::class)->prefix('api')->group(fn () => self::atRoot(self::BEARER_NAMES, function () use ($engineConsumption): void {
            self::siteSectionRoutes('', []);
            Particle::ops('beam-ux-entries', 'beam-ux-entry', self::BEARER_ENTRY_OPS);
            EditorRoutes::nodeActions('beam-ux-entries', $engineConsumption);
        }));
    }

    /** @param  array<string, mixed>  $mountOptions */
    private static function siteSectionRoutes(string $prefix, array $mountOptions): void
    {
        $realm = is_string($mountOptions['realm'] ?? null) ? $mountOptions['realm'] : null;
        $names = $realm === null ? '' : $realm.'.';
        $router = app(Router::class);
        $before = array_fill_keys(array_map('spl_object_id', $router->getRoutes()->getRoutes()), true);

        foreach (['beam-ux-entries' => 'beam-ux-entry', 'beam-ux-site-map' => 'beam-ux-site-map'] as $uri => $resource) {
            Particle::mount($prefix.$uri, $resource)->only(['index'])->idConstraint('uuid')->names($names.$resource)->register();
        }

        if ($realm !== null) {
            // A CRUD index carries no realm or override from the mount itself; stamp what the realm's ops carry. Only
            // the two indexes: the hook-event catalogs mounted beside them stay realm-free, as on the legacy mount.
            foreach ($router->getRoutes()->getRoutes() as $route) {
                if (isset($before[spl_object_id($route)]) || ! str_ends_with((string) $route->getName(), '.index')) {
                    continue;
                }
                $route->defaults(ParticleOperationController::REALM, $realm);
                if (is_array($mountOptions['abilityOverrides'] ?? null)) {
                    $route->defaults(ParticleOperationController::ABILITY_OVERRIDES, $mountOptions['abilityOverrides']);
                }
            }
        }

        Particle::ops($prefix.'beam-ux-redirects', 'beam-ux-redirect', ['traces'], array_filter([
            'names' => $names.'beam-ux-redirect',
            'realm' => $realm,
            'abilityOverrides' => $realm === null ? null : ($mountOptions['abilityOverrides'] ?? null),
        ], fn ($value) => $value !== null));
    }

    private static function atRoot(string $names, \Closure $routes): void
    {
        ApiRoot::mount(app(Router::class), 'beam.ux.api_root', 'ux', function (Router $router) use ($names, $routes): void {
            $router->name($names)->group($routes);
        });
    }
}
