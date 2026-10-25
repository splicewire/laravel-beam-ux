<?php

namespace Splicewire\Beam\Ux\Redirect;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a guest gets at a URL no page answers (ADR-0236 d9–d11). Asked only on the renderer's miss, so a page always
 * wins (d7) and a page that resolves pays nothing; a denied page costs the same lookup as an absent one (§5).
 *
 * A Redirect answers in one response, 301 or 302 for a temporary one (any temporary step makes it 302), with the
 * guest's query string. A page target is its current URL when this reader may see it; a hidden page's URL is looked up
 * in turn; a page the reader may not read, or one that is gone, is the uniform 404 (d10). A path where another Redirect
 * starts is looked up in turn, and any other path is where the guest goes (a host route the renderer can't see may
 * answer it). An address leaves the site, a Record is reached through its Collection's Record Page as this reader reads
 * it. A loop, a dead end (a hidden page with nothing further) or more than `beam.ux.redirects.max_steps` steps is the
 * uniform 404. An answer that depends on the reader is `no-store,
 * private`; otherwise `public, max-age` (`beam.ux.redirects.max_age`, 60), so a browser doesn't keep a 301 for good. In
 * a Preview a Redirect answers 302 with `no-store`; how a Release would leave them is EP-31's.
 */
final class RedirectResolver
{
    public function __construct(private PublicEntryGate $gate, private EntryPathResolver $paths, private RecordPages $records) {}

    public function answer(Request $request, string $path, string $realm, ?Authenticatable $actor, ?PreviewContext $preview = null): ?Response
    {
        $connection = (new BeamUxEntry)->getConnectionName();
        // Asked fresh each miss: a process outlives a migration (a long-running server, a test suite).
        if (! Schema::connection($connection)->hasTable('beam_ux_redirects')) {
            return null;
        }
        $current = RedirectPaths::normalize('/'.ltrim($path, '/'));
        if ($current === null) {
            return null;
        }
        $map = RedirectMap::for($realm, $connection);
        $permanent = $preview === null;
        $private = $preview !== null;
        $seen = [];
        for ($step = 0; $step < (int) config('beam.ux.redirects.max_steps', 5); $step++) {
            if (isset($seen[$current]) || ($redirect = $map->match($current)) === null) {
                return null;
            }
            $seen[$current] = true;
            $permanent = $permanent && (bool) $redirect->permanent;
            $rest = $redirect->match === 'prefix' ? RedirectPaths::rest($current, $redirect->path) : '';

            if ($redirect->target_kind === 'address') {
                return $this->send($request, RedirectPaths::join((string) $redirect->target_url, $rest), $permanent, $private, true);
            }
            if ($redirect->target_kind === 'record') {
                $to = $this->recordUrl($redirect, $realm, $request, $actor);

                return $to === null ? null : $this->send($request, $to, $permanent, true, false);
            }
            if ($redirect->target_kind === 'page') {
                $page = BeamUxEntry::on($connection)->find($redirect->target_entry_id);
                $ancestry = $page === null ? null : $this->paths->ancestry($page);
                if ($ancestry === null) {
                    return null;
                }
                $to = RedirectPaths::join($page->url(), $rest);
                if (! $this->gate->hidden($ancestry, $preview)) {
                    $chain = $this->gate->chainForEntry($page, $realm, $actor, $preview);

                    return $chain === null ? null : $this->send($request, $to, $permanent, $private || $this->gate->isRestricted($chain), false);
                }
                $current = $to;

                continue;
            }
            // A path is looked up in turn when a Redirect starts there. Otherwise the guest goes there: the renderer
            // can't see a host's own routes (a sign-in page, a hand-coded one), so the site answers that URL itself.
            $next = RedirectPaths::join((string) $redirect->target_path, $rest);
            $chain = $this->gate->chainFor($next, $realm, $actor, $preview);
            if ($chain === null && $map->match($next) !== null) {
                $current = $next;

                continue;
            }

            return $this->send($request, $next, $permanent, $private || ($chain !== null && $this->gate->isRestricted($chain)), false);
        }

        return null;
    }

    /** A Record target's URL through its Collection's one Record Page (ADR-0229 d11), read as this reader. */
    private function recordUrl(BeamUxRedirect $redirect, string $realm, Request $request, ?Authenticatable $actor): ?string
    {
        $page = BeamUxEntry::query()->whereNotNull('record_page')->where('type', 'page')->get()
            ->first(fn (BeamUxEntry $entry) => ($entry->getAttribute('record_page')['collection'] ?? null) === $redirect->target_collection);
        if ($page === null) {
            return null;
        }
        $read = clone $request;
        $read->setUserResolver(fn () => $actor);
        foreach ($this->records->records($page, $read, $realm) as $row) {
            if ((string) Arr::get($row, 'id') === (string) $redirect->target_record_id) {
                return $this->records->url($page, $row);
            }
        }

        return null;
    }

    private function send(Request $request, string $to, bool $permanent, bool $private, bool $external): Response
    {
        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $to .= (str_contains($to, '?') ? '&' : '?').$query;
        }
        // An Inertia visit can't follow a redirect to another origin: it gets Inertia's external location.
        if ($external && $request->header('X-Inertia')) {
            return new Response('', Response::HTTP_CONFLICT, ['X-Inertia-Location' => $to]);
        }
        $response = $external ? redirect()->away($to, $permanent ? 301 : 302) : redirect($to, $permanent ? 301 : 302);
        $response->headers->set('Cache-Control', $private
            ? 'no-store, private'
            : 'public, max-age='.(int) config('beam.ux.redirects.max_age', 60));

        return $response;
    }
}
