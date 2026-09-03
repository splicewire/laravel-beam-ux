<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Resolves a {@see BeamUxEntry}'s PUBLIC URL by composing `segment` DOWN the containment tree from the
 * realm root (beamux-entry-charter S3, ADR-0165 §5). The route is **decoupled from `namespace`**
 * (that is disk grouping only, S2 — the "two trees") and **inherited from the containment tree**.
 *
 * **Segment grammar (ADR-0165 §5):**
 *  - bare `segment` or `./segment` — **parent-relative** (folder semantics): appended under the parent's
 *    resolved path, so moving a subtree carries its children's URLs with it.
 *  - `/segment` — **realm root absolute**: resets to the root, ignoring every ancestor above.
 *
 * A null/empty segment contributes nothing (a structural/pass-through node inherits its parent's path).
 * The resolver walks `parent_id` up to the root; a host that has the ancestor chain in hand can pass it
 * to {@see resolveChain()} to avoid per-node queries.
 *
 * Composition seam (ADR-0092): URL inheritance is beam-ux's own engine, not the composed-down nav
 * projection.
 *
 * ⚠️ **This class composes an ADDRESS, not a promise that the address serves — and it is NOT the
 * inverse of {@see EntryPathResolver}.** It walks `parent_id` and applies the segment grammar; that is
 * the whole of it. It never asks whether the entry is in the realm being served, whether its `type` is
 * routable, or whether a *different* row already owns the root-absolute segment it just emitted — all
 * of which `EntryPathResolver` checks, and any of which turns this method's answer into a 404.
 *
 * Measured at `splicewire/www` (beam-docs-satellite ticket 69): this resolver reported corrected URLs
 * for fifteen docs guides whose `realms` column was NULL, which `EntryPathResolver::routable()`'s
 * `whereJsonContains` cannot match — so the composer and the server disagreed about the same rows, in
 * silence, and the corpus 404'd behind a set of URLs that looked right everywhere they were printed.
 * **A composed URL is not evidence of reachability.** Where reachability is what you need, round-trip
 * it — {@see \Splicewire\Beam\Ux\Doctor\BeamUxReachabilityAudit} does exactly that, host-wide.
 */
class UrlResolver
{
    /**
     * The public URL path for an entry, composing its ancestor chain from the realm root. Walks
     * `parent()` up the tree (loads ancestors lazily). Pass a pre-loaded ancestor chain to
     * {@see resolveChain()} to skip the walk.
     */
    public function resolve(BeamUxEntry $entry): string
    {
        $chain = [];
        $node = $entry;

        // Collect root → … → entry by walking parents, then reverse to root-first order.
        while ($node !== null) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $this->resolveChain($chain);
    }

    /**
     * Compose a public URL from an ordered **root-first** chain of entries (`[root, …, leaf]`). Applies
     * the segment grammar per node: a `/`-prefixed segment RESETS the accumulated path to the
     * realm root; a bare/`./` segment appends under it; an empty segment passes through.
     *
     * @param  array<int, BeamUxEntry>  $chain  root-first ancestor chain ending at the target entry
     */
    public function resolveChain(array $chain): string
    {
        $segments = [];

        foreach ($chain as $node) {
            $raw = $node->segment;

            if ($raw === null || $raw === '') {
                // Structural pass-through node: inherits the accumulated path, contributes nothing.
                continue;
            }

            if (str_starts_with($raw, '/')) {
                // Root-absolute: reset to the realm root, ignoring everything above this node.
                $segments = [];
                $piece = ltrim($raw, '/');
            } else {
                // Parent-relative (folder semantics). `./foo` and `foo` are equivalent.
                $piece = $this->stripLeadingDotSlash($raw);
            }

            $piece = trim($piece, '/');

            if ($piece !== '') {
                $segments[] = $piece;
            }
        }

        return '/'.implode('/', $segments);
    }

    private function stripLeadingDotSlash(string $segment): string
    {
        if (str_starts_with($segment, './')) {
            return substr($segment, 2);
        }

        return $segment;
    }
}
