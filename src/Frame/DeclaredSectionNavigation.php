<?php

namespace Splicewire\Beam\Ux\Frame;

use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavNode;

/**
 * The navigation beam-ux registers for a realm when packages have declared seats for it — a CLASS
 * rather than a closure, and that is the whole of its design.
 *
 * ## Why not a closure
 *
 * `NavRegistry` is `PickOne`/`Supersede`, and a host's own provider boots after every package's, so
 * a host registering its own navigation for the same realm replaces this one wholesale. That is the
 * documented override seam and nothing here defends against it. But downstream,
 * {@see FrameNavContribution} has to answer a question the tree itself cannot: *did a PACKAGE
 * produce this navigation, or did the HOST spell it out?* — because the two get different treatment
 * when a node names a route the host does not mount.
 *
 * The obvious answer, stamping the nodes, does not work: {@see \Rushing\DataNav\NavRegistry::build()}
 * round-trips the tree through `toArray()`/`from()` at the active-stamping step, and that strips the
 * `#[Hidden]` meta bag — deliberately, it is what keeps server-only gate tokens off the wire. Any
 * provenance mark on a NODE is erased before a caller ever sees it.
 *
 * So provenance lives on the ENTRY instead. `tryResolve($realm) instanceof self` is exact: it reads
 * whichever entry is live right now, so a host that superseded us answers false, and one that never
 * registered answers true, with no flag to keep in sync and nothing to erase.
 */
class DeclaredSectionNavigation
{
    public function __construct(
        private NavSectionProjector $projector,
        private string $realm,
    ) {}

    /**
     * The declared seats for this navigation's realm.
     *
     * Takes the context to satisfy the registry's declared `callable(NavContext): list<NavNode>`
     * entry type even though the seats do not vary by actor — the gating that DOES vary happens
     * afterwards, in `NavGate`, off the meta this projection stamps.
     *
     * @return array<int, NavNode>
     */
    public function __invoke(?NavContext $context = null): array
    {
        return $this->projector->project($this->realm);
    }
}
