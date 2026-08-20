<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams an entry's **compiled body artifact** — the ES module the page shell imports (ADR-0209 §7–§8).
 *
 * **Guarded entries get artifacts too, and this is the route that makes that safe.** ADR-0122 specified
 * that a guarded guide's RAW MDX is fetched post-gate and compiled in the browser, so nothing guarded
 * appears in server-rendered HTML. Serving a compiled artifact through this same gate-and-stream route
 * preserves both of that ADR's actual invariants — nothing guarded in server HTML, and `no-store`
 * post-gate output so a revoked grant bites on the next request — while taking the MDX compiler out of
 * every bundle, guarded and public alike. That is a deliberate refinement of ADR-0122, recorded because
 * 0122 reasoned in terms of raw MDX specifically.
 *
 * **The same uniform 404 as the page** ({@see PublicEntryGate}). An id that resolves to nothing, an
 * unpublished entry, a denied one, and an entry outside this mount's realm are one answer — otherwise
 * the artifact route becomes the oracle the page route refuses to be.
 *
 * **A missing artifact is a 404 that the doctor has already been complaining about**, never a
 * compile-on-read. Compiling here would put a Node process on the read path, which is the exact trade
 * §7 inverted, and it would make the "no silent client-compile fallback" rule true only by accident.
 */
class EntryArtifactController
{
    public function __construct(
        private PublicEntryGate $gate,
        private EntryArtifactStore $artifacts,
        private CompileEntryBody $compile,
    ) {}

    public function __invoke(Request $request, string $entry): Response
    {
        $defaults = $request->route()?->defaults ?? [];
        $realm = (string) ($defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);

        $model = BeamUxEntry::query()->whereKey($entry)->first();

        abort_if($model === null, Response::HTTP_NOT_FOUND);

        $chain = $this->gate->chainForEntry($model, $realm, $request->user());

        abort_if($chain === null, Response::HTTP_NOT_FOUND);
        abort_unless($this->compile->compilable($model), Response::HTTP_NOT_FOUND);

        $code = $this->artifacts->read($model);

        abort_if($code === null, Response::HTTP_NOT_FOUND);

        $version = $this->artifacts->version($model);
        $response = response($code, Response::HTTP_OK, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // The version key IS the artifact's address, so it is a free strong validator (§7).
            'ETag' => '"'.$version.'"',
        ]);

        if ($this->gate->isRestricted($chain)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        } else {
            // A public artifact is immutable at this address by construction: a body edit mints a new
            // version and therefore a new URL, so it can be cached hard without an invalidation story.
            $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return $response;
    }
}
