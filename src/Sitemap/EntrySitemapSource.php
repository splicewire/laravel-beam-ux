<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Sitemap\Contracts\SitemapSource;
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
class EntrySitemapSource implements SitemapSource
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
        $entries = BeamUxEntry::query()
            ->where('type', UxType::Page->value)
            ->cursor();

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

            yield Url::create($base.$entry->url());
        }
    }

    /**
     * The fine route gate: the entry must resolve to a concrete, non-root
     * containment URL. A page with no segment anywhere in its chain resolves to
     * bare `/` — that is the home index, addressable, so it is allowed; but an
     * entry with no containment placement at all (no realm membership) is not a
     * public page and is skipped.
     */
    private function routable(BeamUxEntry $entry): bool
    {
        return $entry->realm !== null && ! empty($entry->realms);
    }
}
