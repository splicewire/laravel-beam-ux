<?php

namespace Splicewire\Beam\Ux\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **conjunctive ancestor walk** (ADR-0212 §3) — rights composed up the containment chain, in the
 * one place both consumers share so the rule cannot drift between them.
 *
 * To render the entry at `/a/b/c`, the reader must clear **{@see Right::Traverse} on every ancestor**
 * (`a`, `b`) and **{@see Right::Access} on `c`**. An ancestor's `access` is irrelevant to a descendant;
 * an entry's own `traverse` governs its children, not its own body.
 *
 * **Inheritance is not a second lookup — it falls out of conjunction.** An entry with no explicit
 * tokens contributes no constraint (the bound gate returns true on a null column), so it transparently
 * inherits whatever its ancestors impose. That is the "inherit from `parent_id` unless set" behaviour
 * with no second mechanism to keep in agreement.
 *
 * The consequence is that a subtree can only ever **narrow**. A child declaring wider access than its
 * inherited constraint is accepted and **inert** — precisely a `644` file inside a `700` directory,
 * whose permissive mode is unreachable rather than honoured. Accepting-and-reporting beat rejecting,
 * because rejecting would make a perfectly coherent Unix-shaped declaration an error; the doctor names
 * inert declarations instead ({@see \Splicewire\Beam\Ux\Doctor\BeamUxAccessAudit}).
 *
 * **No second traversal.** Both callers already hold the chain — ADR-0209's renderer from its reverse
 * walk, {@see NavProjector} from its single adjacency load — so every method here takes the chain it
 * was handed, in the same root-first shape {@see UrlResolver::resolveChain()} consumes. This class
 * never queries.
 */
class EntryAccessResolver
{
    public function __construct(private EntryAccessGate $gate) {}

    /**
     * Whether the actor may **render** the last entry in a root-first chain: `traverse` on every
     * ancestor, `access` on the target. Fires post-resolution and pre-body-read (ADR-0209 §5); every
     * denial is the caller's uniform 404.
     *
     * An empty chain denies — a caller with nothing resolved has nothing to authorize.
     *
     * @param  array<int, BeamUxEntry>  $chain  root-first ancestors, target last
     */
    public function canRender(?Authenticatable $actor, array $chain): bool
    {
        $chain = array_values($chain);
        $target = array_pop($chain);

        if ($target === null) {
            return false;
        }

        return $this->canTraverseAll($actor, $chain)
            && $this->gate->allows($actor, $target, Right::Access);
    }

    /**
     * Whether the actor may **see the last entry in a nav projection**: the `traverse` chain
     * *inclusive of the node itself*, plus its own `access` (ADR-0212 §4).
     *
     * Nav deliberately departs from a faithful Unix port, where `ls` on a readable directory names
     * every child regardless of the children's own modes. That behaviour — a node visible in the
     * sidebar that 404s on click — would resurrect the titles leak ADR-0122 named as a hard
     * requirement, and would make ADR-0209's uniform 404 a 404 that only sometimes means "not here".
     * The upsell tease it would enable is a product feature ADR-0122 already deferred deliberately; it
     * should arrive as itself, not as a side effect of nav semantics.
     *
     * The node's own `traverse` counts here (unlike {@see canRender}) because listing a node IS
     * traversal into the subtree it heads — which is exactly what yields the **unlisted page**:
     * `traverse` denied with `access` open ⇒ 200 by direct URL, absent from nav.
     *
     * @param  array<int, BeamUxEntry>  $chain  root-first ancestors, node last
     */
    public function canList(?Authenticatable $actor, array $chain): bool
    {
        $chain = array_values($chain);
        $node = end($chain);

        if ($node === false) {
            return false;
        }

        return $this->canTraverseAll($actor, $chain)
            && $this->gate->allows($actor, $node, Right::Access);
    }

    /**
     * Whether the actor holds one right on one row, with no chain involved — the seam the doctor's
     * inert-declaration check compares an entry's own grant against its inherited constraint through.
     */
    public function permits(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool
    {
        return $this->gate->allows($actor, $entry, $right);
    }

    public function gate(): EntryAccessGate
    {
        return $this->gate;
    }

    /**
     * The `traverse` conjunction over a chain. Fail-closed and short-circuiting: the first denied node
     * ends the walk, and in nav that same denial prunes the whole subtree beneath it (lifting orphans
     * to the grandparent is incoherent, because a child's URL is composed from its parent's segment
     * chain — a lifted child has no reachable URL).
     *
     * @param  array<int, BeamUxEntry>  $entries
     */
    private function canTraverseAll(?Authenticatable $actor, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (! $this->gate->allows($actor, $entry, Right::Traverse)) {
                return false;
            }
        }

        return true;
    }
}
