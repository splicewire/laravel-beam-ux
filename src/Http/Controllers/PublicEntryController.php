<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\HttpFoundation\Response;

/**
 * **The public entry renderer** (ADR-0209) — the missing half of ADR-0165. The containment tree could
 * always tell you an entry's URL; this is what resolves a request back the other way and serves it.
 *
 * Mounted by the HOST via `Route::beamUxSite()` as the last line of its `web.php`, never auto-registered:
 * Laravel matches in registration order, so a catch-all registered last shadows nothing the host has
 * already claimed — and only the host can guarantee that ordering (ADR-0209 §2, ADR-0116's "host owns
 * the wire and the policy"). A package silently claiming every unmatched URL in an application is a day
 * of debugging.
 *
 * **It serves the `site` realm.** `realms` is a flat multi-membership list, so one path may be
 * resolvable under several realms' trees; walking any realm the request happened to resolve into would
 * make a public URL a door into a guarded realm — a leak shaped like a feature (§3). The realm is a
 * mount argument, so this is widenable; `site` is the default and the only shipped case.
 *
 * **It reads no realm `guard`.** Coarse site privacy is middleware on the host's mount — a private site
 * mounts the macro inside its `auth` group and every entry inherits it. Per-entry gating is
 * {@see PublicEntryGate} (§4).
 *
 * **The body is never compiled here.** Props carry a URL to the compiled artifact
 * ({@see EntryArtifactController}), which was produced at save time; a page whose artifact is missing
 * fails loudly rather than degrading to shipping an MDX compiler to the reader (§7).
 */
class PublicEntryController
{
    public function __construct(
        private PublicEntryGate $gate,
        private UrlResolver $urls,
        private EntryArtifactStore $artifacts,
        private NavProjector $nav,
        private EntryRenderer $renderer,
    ) {}

    public function __invoke(Request $request, string $path = ''): Response
    {
        $defaults = $request->route()?->defaults ?? [];

        $realm = (string) ($defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        $page = (string) ($defaults['beamUxPage'] ?? '');
        $withNav = (bool) ($defaults['beamUxNav'] ?? true);

        $actor = $request->user();
        $chain = $this->gate->chainFor($path, $realm, $actor);

        // The uniform 404: unresolvable, unpublished and denied are one answer (§5).
        abort_if($chain === null, Response::HTTP_NOT_FOUND);

        $entry = $chain[array_key_last($chain)];

        $response = $this->renderer->render($page, [
            'entry' => [
                'id' => (string) $entry->getKey(),
                'slug' => (string) $entry->slug,
                'title' => $entry->title,
                'type' => $entry->type?->value,
                'format' => $entry->format?->value,
                'url' => $this->urls->resolveChain($chain),
            ],
            // The artifact is addressed, not inlined: it is a JS module the shell imports, and keeping
            // it out of the Inertia payload is what lets a public one be cached by its version while
            // the page shell itself stays per-request.
            'artifact' => [
                'url' => $this->artifactUrl($request, $entry),
                'version' => $this->artifacts->version($entry),
            ],
            // The already-gated nav for the whole realm, so a shell renders `<SiteNav rootPath>` — the
            // site nav and the docs sidebar out of ONE payload (ADR-0210 §5) — without a second
            // round trip. `withNav: false` at mount time is the escape hatch for a host that caches
            // and injects its own.
            'nav' => $withNav ? $this->nav->project($realm, $actor)->toArray() : null,
        ], );

        if ($this->gate->isRestricted($chain)) {
            // Post-gate output for a restricted body: never stored, so a revoked grant bites on the
            // next request rather than living on in a shared cache (ADR-0122, ADR-0209 §8).
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * The URL of the entry's compiled artifact, on the artifact route the same macro mounted. Resolved
     * by name off the route's own default so a host that relocated the mount does not have to tell the
     * renderer twice.
     *
     * **The version is IN the address**, and it has to be. ADR-0209 §7 justifies serving the artifact
     * `immutable, max-age=1y` on the grounds that "a body edit mints a new version and therefore a new
     * URL" — but the version rode alongside the URL as a sibling prop and never entered it, so the
     * address never moved. A reader who had loaded a page once got that artifact back from cache for a
     * year, with `immutable` telling the browser not even to revalidate against the ETag. Every
     * subsequent edit to that entry was invisible to them. Found on `splicewire/www` while re-authoring
     * the docs index (beam-docs-satellite ticket 08): the server served the new module correctly, and
     * the browser never asked for it.
     */
    private function artifactUrl(Request $request, BeamUxEntry $entry): string
    {
        $name = (string) ($request->route()?->defaults['beamUxArtifactRoute'] ?? '');

        if ($name === '' || ! app('router')->has($name)) {
            return '';
        }

        return route($name, array_filter([
            'entry' => $entry->getKey(),
            'version' => $this->artifacts->version($entry),
        ]));
    }
}
