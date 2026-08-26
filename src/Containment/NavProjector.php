<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Projects a realm's containment tree into a `rushing/laravel-data-nav` {@see NavTree} — the
 * NAVIGATION projection of the org spine (beamux-entry-charter S3, ADR-0165: "Navigation is another
 * projection, via `laravel-data-nav`'s `NavTree`"). The `href` of each node is the inherited public URL
 * from {@see UrlResolver}, so the nav rides the SAME containment tree that derives the route (never
 * `namespace`).
 *
 * **Composition seam (ADR-0092):** this projection composes DOWN — it reuses `laravel-data-nav`'s
 * `NavTree` rather than rebuilding a nav primitive. What beam-ux owns is the `site` realm + containment
 * + URL inheritance it projects FROM.
 *
 * **Multiplicity IS built (ticket 03):** an entry's `realms` fallback stack means it can be reachable in
 * several realms at once — the projector takes ONE realm at a time; a host renders several realms' navs
 * by calling `project()` once per realm.
 *
 * **This projection GATES (ADR-0212 §4) — it previously gated nothing at all.** It filtered on realm,
 * `type = page`, and non-null `segment`, and not even {@see EntryPublishGate}. Converting docs to
 * entries on top of that would leak every draft and gated title into the sidebar, defeating outright
 * the server-authoritative index app ADR-0122 named as a hard requirement. Two passes now run:
 *
 *  - **publish**, cheap-filtered into the query where the bound gate is the default marking one (it is
 *    a column, so the filter is free) and always re-asserted in memory through the bound port;
 *  - **access**, in memory after the single adjacency load — so no N+1 is introduced — where a node
 *    appears only if the reader clears the `traverse` chain to it AND its own `access`, and a denied
 *    node prunes its **entire subtree** (lifting orphans to the grandparent is incoherent, since a
 *    child's URL is composed from its parent's segment chain).
 *
 * ADR-0122's caching split is preserved verbatim: the **user-independent** enumerated tree is what a
 * host may cache, and the **gate pass runs live on every request**, so a revoked grant bites on the
 * next request with no stale-authorization window. That is why the actor is a per-call argument and
 * never constructor state.
 */
class NavProjector
{
    private EntryAccessResolver $access;

    /**
     * The access resolver defaults to the OOTB {@see TokenAccessGate} rather than to null-means-open:
     * a hand-constructed projector must still gate, or the leak this class was fixed for returns
     * through the back door. The publish gate stays nullable because its default binding needs the
     * composed-down beam-workflows `LifecycleService`; unset, every entry counts as published —
     * exactly what {@see WorkflowMarkingPublishGate} itself reports for an unmanaged entry, so the
     * degrade is the same answer rather than a second policy.
     */
    public function __construct(
        private UrlResolver $urls = new UrlResolver,
        ?EntryAccessResolver $access = null,
        private ?EntryPublishGate $publish = null,
    ) {
        $this->access = $access ?? new EntryAccessResolver(new TokenAccessGate);
    }

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
     *
     * `$actor` is the reader the access pass runs for — `null` is the anonymous visitor, not a bypass.
     * It is an argument rather than constructor state precisely so the gate pass can run live per
     * request over a tree a host is free to cache (ADR-0212 §4).
     */
    public function project(string $realm, ?Authenticatable $actor = null): NavTree
    {
        $query = BeamUxEntry::query()
            ->whereJsonContains('realms', $realm)
            ->where('type', UxType::Page->value);

        // The publish filter, pushed into the query where it is provably free and provably safe: the
        // marking is a column, and the DEFAULT binding's answer is a pure function of it (null/unmanaged
        // or the published marking). A host that re-bound the port may publish on some other basis and
        // could be MORE permissive than this predicate, so the prefilter is applied only for the default
        // binding — the in-memory pass below asks the bound gate either way and is the real authority.
        if ($this->publish instanceof WorkflowMarkingPublishGate
            && Schema::hasColumn('beam_ux_entries', 'workflow_marking')) {
            $query->where(fn ($q) => $q
                ->whereNull('workflow_marking')
                ->orWhere('workflow_marking', BeamUxEntry::MARKING_PUBLISHED));
        }

        // NOTE: segment-less entries are LOADED here and dropped in `nodesFor()` instead, because the two
        // things "is not a nav destination" and "has no descendants worth showing" are not the same
        // statement — and filtering in the query conflated them with total effect.
        //
        // The realm root (ADR-0209 §9) is a `page` row with a null `segment`, and it is the parent of
        // everything in the realm. Excluding it from the load meant the recursion's top level — which
        // looks for `parent_id === null` — matched nothing at all, so `project()` returned an EMPTY tree
        // on every correctly-seeded host. Found on `splicewire/www` (beam-docs-satellite ticket 07): the
        // docs subtree resolved, gated, compiled and served, and arrived with `nav: {"items": []}`.
        //
        // The original reason for the filter is preserved exactly where it belongs: an unplaced entry
        // (auto-provisioned on an author's first visit) still never becomes a nav item, and colliding
        // root-URL hrefs still cannot happen, because such a node emits no `NavLink`. What changes is
        // that its CHILDREN are no longer discarded with it — the projector now steps through a
        // segment-less node the same way `EntryPathResolver` steps through a pass-through node.

        // Order siblings by an optional host-provided `nav_order` (like `title`, this column is
        // host-supplied — guard on its presence so a consumer without it isn't broken by an orderBy on a
        // missing column). `slug` is the stable tiebreaker / fallback for unordered entries.
        if (Schema::hasColumn('beam_ux_entries', 'nav_order')) {
            $query->orderBy('nav_order');
        }

        // ONE adjacency load; both gate passes then run in memory over it, so gating adds no query.
        $entries = $query->orderBy('slug')->get();

        return NavTree::make($this->nodesFor($entries, null, [], $actor));
    }

    /**
     * Recursively build the nav nodes for one parent level. `$trail` is the root-first ancestor chain so
     * each node's URL is composed via {@see UrlResolver::resolveChain()} without re-walking parents —
     * and so the access pass evaluates the conjunction over a chain it already holds.
     *
     * A node that fails either gate is dropped **with its whole subtree**: it is not recursed into, so
     * nothing beneath a denied ancestor can surface. This is the one place the prune happens.
     *
     * A node with **no segment** is a pass-through: it is not a destination (no URL resolves to it), so
     * it emits no link, but its children are spliced into THIS level with the trail extended by it — so
     * their hrefs still compose through it. Both gates run on it first, which is what keeps "a denied
     * ancestor hides its subtree" true for structural nodes as well as addressable ones.
     *
     * @param  Collection<int, BeamUxEntry>  $all
     * @param  array<int, BeamUxEntry>  $trail
     * @return array<int, NavLink>
     */
    private function nodesFor(Collection $all, ?string $parentId, array $trail, ?Authenticatable $actor): array
    {
        $nodes = [];

        // Group headings (ADR-0213 §8), keyed by `nav_group` and holding the INDEX of the heading node
        // in `$nodes` — so a group lands at the position of its first member and therefore inherits the
        // `nav_order` its members were already sorted by, with no second sort and no group_order column.
        $groups = [];

        $visible = $all
            ->where('parent_id', $parentId)
            ->reject(fn (BeamUxEntry $entry) => $this->publish !== null && ! $this->publish->isPublished($entry))
            ->reject(fn (BeamUxEntry $entry) => ! $this->access->canList($actor, [...$trail, $entry]));

        foreach ($visible as $entry) {
            $chain = [...$trail, $entry];
            $children = $this->nodesFor($all, $entry->getKey(), $chain, $actor);

            if ($entry->segment === null || $entry->segment === '') {
                // Pass-through: lift the children, emit nothing for the node itself.
                $contribution = $children;
            } else {
                $contribution = [NavLink::make(
                    title: $entry->title ?? Str::headline((string) $entry->slug),
                    href: $this->urls->resolveChain($chain),
                    children: $children,
                )];
            }

            // Ungrouped children stay at the parent's level — the pre-0213 behaviour, unchanged, and
            // the reason a host that declares no `nav_group` anywhere sees a byte-identical projection.
            $group = $this->groupOf($entry);

            if ($group === null) {
                $nodes = [...$nodes, ...$contribution];

                continue;
            }

            if (! array_key_exists($group, $groups)) {
                $groups[$group] = count($nodes);
                // An href-less heading: visible, ordered, NOT addressable. `NavLink::make()`'s `href`
                // was already nullable, which is why §8 needed no schema change — the only thing
                // missing was that this projector never emitted one.
                $nodes[] = NavLink::make(title: $group, href: null, children: []);
            }

            $heading = $nodes[$groups[$group]];
            $heading->children = [...$heading->children, ...$contribution];
        }

        return $nodes;
    }

    /**
     * An entry's nav-group label, or null when it declares none.
     *
     * Guarded on the ATTRIBUTE rather than on `Schema::hasColumn` (the guard `nav_order` uses) because
     * this is a per-row read on a model already hydrated, not a clause pushed into a query: a host that
     * has not migrated ADR-0213 simply has no such attribute, and `getAttribute` answers null for it
     * without a schema round trip per node.
     */
    private function groupOf(BeamUxEntry $entry): ?string
    {
        $group = $entry->getAttribute('nav_group');

        return is_string($group) && $group !== '' ? $group : null;
    }
}
