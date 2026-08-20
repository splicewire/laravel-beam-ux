<?php

namespace Splicewire\Beam\Ux\Containment;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **inverse of {@see UrlResolver}** (ADR-0209 §1) — resolves a public request PATH back to the
 * containment chain that composes to it. `UrlResolver` walks a chain DOWN into a URL; serving needs the
 * other direction, and ADR-0165 §5's segment grammar makes that direction genuinely two-phase rather
 * than a mirror image.
 *
 * **Phase 1 — the root-absolute prefix match.** Any node's `segment` may begin with `/`, which RESETS
 * the accumulated path to the realm root and ignores every ancestor above it. Such a node resolves to a
 * shallow URL no matter how deep it sits in the tree, so a pure top-down walk can never reach it. Before
 * walking, then, the longest prefix of the requested path is looked up as a literal root-absolute
 * segment, longest-first, and the remainder is walked relative to whatever that finds. This is the phase
 * that makes re-rooting a docs subtree to `/beam/docs` by editing ONE row work — the capability the
 * containment model was chosen for, and the reason "forbid absolute segments on routable pages" was
 * rejected.
 *
 * **Phase 2 — the top-down walk.** One indexed `(parent_id, segment)` lookup per remaining path piece,
 * descending from the anchor (the realm root when phase 1 matched nothing). Pass-through nodes — a null
 * or empty `segment` — contribute no URL piece, so the walk transparently steps through them.
 *
 * **This class resolves STRUCTURE only.** It applies no publish gate, no access gate, and never reads a
 * body: ADR-0209 §5 puts both gates after resolution and before the body read, in the caller. Keeping
 * them out of here is what lets the renderer hand the resolved chain straight to
 * {@see \Splicewire\Beam\Ux\Access\EntryAccessResolver::canRender()} with no second traversal.
 *
 * **`page` is the sole routable type** (ADR-0209 §6, matching `EntrySitemapSource` and
 * {@see NavProjector}). A `layout` / `template` / `component` / `theme` entry is an authoring artifact
 * with no URL, so it is not a walkable node — including as an intermediate, where treating it as one
 * would let a structural artifact become part of a public path.
 */
class EntryPathResolver
{
    /**
     * Resolve a public path to its **root-first containment chain** (`[root, …, target]`), or null when
     * nothing in the realm resolves to it.
     *
     * The chain is the entry's real containment ancestry, NOT a reconstruction of the URL's pieces —
     * those differ exactly when a root-absolute segment is involved, and it is the ancestry that the
     * access conjunction is defined over (ADR-0212 §3: `traverse` on ancestors, `access` on the target).
     *
     * @return array<int, BeamUxEntry>|null
     */
    public function resolve(string $path, string $realm = BeamUxEntry::REALM_SITE): ?array
    {
        $pieces = $this->pieces($path);

        // Phase 1, longest-prefix-first: the most specific root-absolute declaration wins, so a docs
        // root at `/beam/docs` is preferred over a home page at `/` that happens to have a `beam` child.
        // `$take === 0` is the `/`-segment home page — still an absolute declaration, just the shallowest
        // one — which is why the loop runs down to zero rather than stopping at one.
        for ($take = count($pieces); $take >= 0; $take--) {
            $anchor = $this->absolute(array_slice($pieces, 0, $take), $realm);

            if ($anchor === null) {
                continue;
            }

            $chain = $this->descend($anchor, array_slice($pieces, $take), $realm);

            if ($chain !== null) {
                return [...$this->ancestry($anchor), ...$chain];
            }
        }

        // Phase 2: the plain top-down walk from the realm root. Its absence is "nothing to serve" —
        // the root is seeded by install and this resolver never writes (ADR-0209 §9).
        $root = $this->realmRoot($realm);

        if ($root === null) {
            return null;
        }

        $chain = $this->descend($root, $pieces, $realm);

        return $chain === null ? null : [$root, ...$chain];
    }

    /**
     * The entry whose own `segment` is the literal root-absolute spelling of these path pieces — `[]`
     * meaning the bare `/` home page.
     *
     * ADR-0209 §10 made `(parent_id, segment)` unique, which does NOT make an absolute segment globally
     * unique: two nodes under different parents may both declare `/docs`. That is a genuine authoring
     * ambiguity rather than something to resolve by policy, so the tie is broken deterministically
     * (shallowest parent first, then by id) instead of by whichever row the driver happened to return —
     * the silent-wrong-row class of bug `BeamUxEntryBodyController` documents finding live on slug
     * ambiguity.
     *
     * @param  array<int, string>  $pieces
     */
    protected function absolute(array $pieces, string $realm): ?BeamUxEntry
    {
        return $this->routable($realm)
            ->where('segment', '/'.implode('/', $pieces))
            ->orderByRaw('case when parent_id is null then 0 else 1 end')
            ->orderBy('id')
            ->first();
    }

    /**
     * Walk `$pieces` down from `$anchor`, one `(parent_id, segment)` lookup each, and return the nodes
     * BELOW the anchor (root-first, target last) — or null the moment a piece has no child to match.
     *
     * A pass-through child (null/empty `segment`) consumes no piece but is a legitimate step, so each
     * piece is matched against both the direct children AND, one level deep, the children of any
     * pass-through child. Deeper pass-through chains are deliberately not searched: that would turn a
     * bounded lookup into an unbounded fan-out on every 404, and a public URL routed through two
     * stacked structural nodes is a shape nothing authors.
     *
     * @param  array<int, string>  $pieces
     * @return array<int, BeamUxEntry>|null
     */
    protected function descend(BeamUxEntry $anchor, array $pieces, string $realm): ?array
    {
        $chain = [];
        $node = $anchor;

        foreach ($pieces as $piece) {
            $next = $this->child($node, $piece, $realm);

            if ($next !== null) {
                $chain[] = $next;
                $node = $next;

                continue;
            }

            $through = $this->passThroughChild($node, $piece, $realm);

            if ($through === null) {
                return null;
            }

            $chain = [...$chain, ...$through];
            $node = $through[array_key_last($through)];
        }

        return $chain;
    }

    /**
     * The direct child of `$parent` whose `segment` is this piece, in either of the two parent-relative
     * spellings ADR-0165 §5 treats as equivalent (`foo` and `./foo`).
     */
    protected function child(BeamUxEntry $parent, string $piece, string $realm): ?BeamUxEntry
    {
        return $this->routable($realm)
            ->where('parent_id', $parent->getKey())
            ->whereIn('segment', [$piece, './'.$piece])
            ->orderBy('id')
            ->first();
    }

    /**
     * One level of pass-through: a segment-less child of `$parent` that itself has a child matching the
     * piece. Returns `[passThrough, match]` so the caller's chain keeps the real ancestry — the
     * pass-through node is an ancestor for `traverse` purposes even though it contributes no URL piece.
     *
     * @return array<int, BeamUxEntry>|null
     */
    protected function passThroughChild(BeamUxEntry $parent, string $piece, string $realm): ?array
    {
        $candidates = $this->routable($realm)
            ->where('parent_id', $parent->getKey())
            ->where(fn ($q) => $q->whereNull('segment')->orWhere('segment', ''))
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            $match = $this->child($candidate, $piece, $realm);

            if ($match !== null) {
                return [$candidate, $match];
            }
        }

        return null;
    }

    /**
     * The realm's canonical root entry — READ ONLY. {@see BeamUxEntry::rootFor()} is a `firstOrCreate`,
     * and a GET that silently INSERTs breaks on read-replica topologies and races under concurrent
     * first-hits (ADR-0209 §9), so the renderer's path never touches it: `splicewire:beam:install` seeds
     * the root, and its absence here simply means there is nothing to serve.
     */
    protected function realmRoot(string $realm): ?BeamUxEntry
    {
        return BeamUxEntry::query()
            ->where('namespace', 'realms')
            ->where('slug', $realm)
            ->first();
    }

    /**
     * A node's root-first ancestry INCLUDING itself, walked up `parent_id`. Used only after a phase-1
     * absolute match, where the URL's own pieces say nothing about how deep the node really sits.
     *
     * @return array<int, BeamUxEntry>
     */
    protected function ancestry(BeamUxEntry $entry): array
    {
        $chain = [];
        $node = $entry;

        while ($node !== null) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $chain;
    }

    /**
     * The base query for a routable node: in this realm's membership stack, and of the sole routable
     * `type`. `realms` is the flat multi-membership list (an entry is reachable under EVERY realm it
     * names), matching how {@see NavProjector} selects — the two must agree or a page could be listed
     * in a nav it cannot be served from.
     */
    protected function routable(string $realm): \Illuminate\Database\Eloquent\Builder
    {
        $query = BeamUxEntry::query()->where('type', UxType::Page->value);

        // Guarded like every other aspect column: a consumer that has not migrated containment yet
        // resolves nothing rather than dying on a missing column.
        if (Schema::hasColumn('beam_ux_entries', 'realms')) {
            $query->whereJsonContains('realms', $realm);
        } else {
            $query->where('realm', $realm);
        }

        return $query;
    }

    /**
     * Normalize a request path into its pieces: leading/trailing slashes stripped, empty pieces
     * (a `//` or a trailing `/`) dropped. `/` and `''` both yield `[]`.
     *
     * @return array<int, string>
     */
    protected function pieces(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            fn (string $piece) => $piece !== '',
        ));
    }
}
