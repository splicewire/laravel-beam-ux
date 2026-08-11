<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Projects a realm's containment tree into a `rushing/laravel-data-nav` {@see NavTree} — the
 * NAVIGATION projection of the org spine (beamux-entry-charter S3, ADR-0165: "Navigation is another
 * projection, via `laravel-data-nav`'s `NavTree`"). The `href` of each node is the inherited public URL
 * from {@see UrlResolver}, so the nav rides the SAME containment tree that derives the route (never
 * `namespace`).
 *
 * **Vendor seam (ADR-0092):** this projection is FREE-TIER — it reuses `laravel-data-nav`'s `NavTree`
 * rather than rebuilding a nav primitive. The paid engine is the `site` realm + containment + URL
 * inheritance it projects FROM.
 *
 * **Multiplicity IS built (ticket 03):** an entry's `realms` fallback stack means it can be reachable in
 * several realms at once — the projector takes ONE realm at a time; a host renders several realms' navs
 * by calling `project()` once per realm.
 */
class NavProjector
{
    public function __construct(private UrlResolver $urls = new UrlResolver) {}

    /**
     * Build a {@see NavTree} for a realm's public containment tree. Loads every entry whose `realms`
     * fallback stack contains the given realm, assembles the parent→children adjacency in memory (one
     * query, no N+1), and recurses from the roots (top-level nodes: `parent_id === null`), stamping each
     * node's inherited URL as its `href`.
     *
     * Nav is a projection of navigable **content** (`page` entries): a `template`/`layout`/`component`
     * has no route (charter §Q1), so it never becomes a nav destination even if it incidentally shares
     * the realm (e.g. a template minted with the default `realm`/`realms`). Filtering by the `page`
     * type keeps those structural authoring artifacts out of the public nav without touching their rows.
     */
    public function project(string $realm): NavTree
    {
        $query = BeamUxEntry::query()
            ->whereJsonContains('realms', $realm)
            ->where('type', UxType::Page->value);

        // Only PLACED entries are nav items. An entry can exist (e.g. auto-provisioned on an author's first
        // visit so the page is editable) without being placed in the nav — those carry a null `segment`.
        // Excluding them keeps such entries out of the projected nav (and avoids colliding hrefs when
        // several unplaced entries would all resolve to the root URL). Guarded on the column's presence.
        if (Schema::hasColumn('beam_ux_entries', 'segment')) {
            $query->whereNotNull('segment');
        }

        // Order siblings by an optional host-provided `nav_order` (like `title`, this column is
        // host-supplied — guard on its presence so a consumer without it isn't broken by an orderBy on a
        // missing column). `slug` is the stable tiebreaker / fallback for unordered entries.
        if (Schema::hasColumn('beam_ux_entries', 'nav_order')) {
            $query->orderBy('nav_order');
        }

        $entries = $query->orderBy('slug')->get();

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
                    title: $entry->title ?? Str::headline((string) $entry->slug),
                    href: $this->urls->resolveChain($chain),
                    children: $this->nodesFor($all, $entry->getKey(), $chain),
                );
            })
            ->values()
            ->all();
    }
}
