<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Sitemap\Contracts\LiveSitemapSource;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Yields sitemap URLs for {@see BeamUxEntry} rows where **route × published-marking
 * × entitlement** ALL hold (charter S5, ADR-0166). Supersedes the dead
 * `satellite-publishing` `PostSitemapSource`; once MDX pages are entries it also
 * supersedes the arm's `RouteSitemapSource` route-table heuristic with real
 * publish-state filtering.
 *
 * The three gates:
 *
 *  1. **Route** — only a routable rendering has a public URL. `UxType::Page` is
 *     the sole routable type (layouts/templates/components are not addressable);
 *     the entry must resolve to a concrete containment URL (a page with no
 *     placement in the containment tree yields nothing). Context-following
 *     residency (ADR-0166 §4) means running in a tenant context already scopes the
 *     query to that tenant's entries — no per-tenant filter here.
 *  2. **Published marking** — {@see EntryPublishGate}. Wired in S6: the default
 *     {@see WorkflowMarkingPublishGate} reads the entry's persisted
 *     `workflow_marking` (a managed entry is public only at its published place; an
 *     unmanaged entry — no binding — stays published). A host may re-bind the port.
 *  3. **Entitlement** — {@see EntryEntitlementGate}. Never leak gated content; the
 *     default {@see PublicEntitlementGate} treats every entry as public, a gating
 *     host re-binds it.
 */
class EntrySitemapSource implements LiveSitemapSource
{
    public function __construct(
        private SitemapBaseUrlResolver $baseUrl,
        private EntryPublishGate $published,
        private EntryEntitlementGate $entitlement,
    ) {}

    public function urls(): iterable
    {
        $base = $this->baseUrl->baseUrl();

        // Route gate (coarse): only Page-typed entries are routable. The
        // per-entry URL resolution + marking + entitlement gates run below.
        $routing = ['id', 'slug', 'namespace', 'type', 'realm', 'realms', 'parent_id', 'segment',
            'access', 'traverse', 'requirements', 'record_page', 'workflow_marking', 'workflow_version',
            'schema_ref', 'format', 'residency_mode'];
        $columns = array_values(array_intersect($routing, \Illuminate\Support\Facades\Schema::getColumnListing('beam_ux_entries')));
        $all = BeamUxEntry::query()->select($columns)->where('type', UxType::Page->value)->get();
        $resolver = app(\Splicewire\Beam\Ux\Containment\EntryPathResolver::class)->snapshot($all);
        $entries = $all->filter(fn ($entry) => $entry->type === UxType::Page);

        foreach ($entries as $entry) {
            if (! $this->routable($entry)) {
                continue;
            }

            if (! $this->published->isPublished($entry)) {
                continue;
            }

            if (! $this->entitlement->isPublic($entry)) {
                continue;
            }

            $chain = $resolver->ancestry($entry);
            if ($chain === null || ! app(\Splicewire\Beam\Ux\Access\EntryAccessResolver::class)->canList(null, $chain)) {
                continue;
            }

            if (\Splicewire\Beam\Ux\Bindings\RecordPages::parameter($entry->segment) !== null) {
                $request = \Illuminate\Http\Request::create('/');
                $request->setUserResolver(fn () => null);
                $pages = app(\Splicewire\Beam\Ux\Bindings\RecordPages::class);
                foreach ($pages->records($entry, $request, BeamUxEntry::REALM_SITE) as $row) {
                    $url = $pages->url($entry, $row);
                    $resolved = $url === null ? null : $resolver->resolve($url);
                    if ($resolved !== null && end($resolved)->getKey() === $entry->getKey()) {
                        yield Url::create($base.$url);
                    }
                }

                continue;
            }
            yield Url::create($base.$entry->url());
        }
    }

    /**
     * The fine route gate: the entry must be a public page — a MEMBER of the public `site` realm
     * (ADR-0165, the public content/URL plane), read from the flat `realms` list, NOT the primary
     * `realm`. Reachability is membership: an entry primary in another realm but a member of `site`
     * is site-reachable and belongs in the feed, while a `site`-primary entry whose `realms` omits
     * `site` does not. An entry not in `site` is not public — its URL is gated or, on a satellite,
     * not routed at all (sites-sweep P7: audiostud's sitemap listed the unrouted `/register`, an
     * auth-realm page). This keeps the feed to public `site` pages; it does not by itself guarantee a
     * 2xx (a public `site` page may still 302 signed-out — e.g. audiostud `/studio`).
     */
    private function routable(BeamUxEntry $entry): bool
    {
        return $entry->isReachableIn(BeamUxEntry::REALM_SITE);
    }
}
