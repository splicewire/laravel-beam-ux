<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Support\Collection;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\Sitemap;

/**
 * Projects a {@see Sitemap}'s containment tree into a `rushing/laravel-data-nav` {@see NavTree} — the
 * NAVIGATION projection of the org spine (beamux-entry-charter S3, ADR-0165: "Navigation is another
 * projection, via `laravel-data-nav`'s `NavTree`"). The `href` of each node is the inherited public URL
 * from {@see UrlResolver}, so the nav rides the SAME containment tree that derives the route (never
 * `namespace`).
 *
 * **Vendor seam (ADR-0092):** this projection is FREE-TIER — it reuses `laravel-data-nav`'s `NavTree`
 * rather than rebuilding a nav primitive. The paid engine is the `site` realm + Sitemap + containment +
 * URL inheritance it projects FROM.
 *
 * **Multiplicity is NOT built** but FK-shaped: the projector takes ONE sitemap (the default one today);
 * a future multi-sitemap host loops it per sitemap without a shape change.
 */
class NavProjector
{
    public function __construct(private UrlResolver $urls = new UrlResolver) {}

    /**
     * Build a {@see NavTree} for a sitemap's public containment tree. Loads every entry in the sitemap,
     * assembles the parent→children adjacency in memory (one query, no N+1), and recurses from the roots
     * (top-level nodes: `parent_id === null`), stamping each node's inherited URL as its `href`.
     */
    public function project(Sitemap $sitemap): NavTree
    {
        $entries = BeamUxEntry::query()
            ->where('sitemap_id', $sitemap->getKey())
            ->get();

        return NavTree::make($this->nodesFor($entries, null, []));
    }

    /**
     * Recursively build the nav nodes for one parent level. `$trail` is the root-first ancestor chain so
     * each node's URL is composed via {@see UrlResolver::resolveChain()} without re-walking parents.
     *
     * @param  Collection<int, BeamUxEntry>  $all
     * @param  array<int, BeamUxEntry>  $trail
     * @return array<int, NavLink>
     */
    private function nodesFor(Collection $all, ?string $parentId, array $trail): array
    {
        return $all
            ->where('parent_id', $parentId)
            ->map(function (BeamUxEntry $entry) use ($all, $trail) {
                $chain = [...$trail, $entry];

                return NavLink::make(
                    title: $entry->slug,
                    href: $this->urls->resolveChain($chain),
                    children: $this->nodesFor($all, $entry->getKey(), $chain),
                );
            })
            ->values()
            ->all();
    }
}
