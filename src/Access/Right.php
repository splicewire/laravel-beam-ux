<?php

namespace Splicewire\Beam\Ux\Access;

use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The two conjunctive entry rights (ADR-0212 §2), deliberately mirroring Unix's `x`/`r` split rather
 * than collapsing to one right. Each names a nullable token-list column on `beam_ux_entries`.
 *
 *  - {@see self::Traverse} — may this reader reach THROUGH this entry to its descendants, and see it
 *    in a projected nav. Evaluated at every ancestor position on the way to a target.
 *  - {@see self::Access} — may this reader read this entry's BODY. Retains app ADR-0122's `access:`
 *    meaning exactly, so frontmatter already carrying that key migrates with zero edits.
 *
 * Two rights rather than one buys the **unlisted page**: `traverse` denied with `access` open yields
 * an entry reachable by direct link and absent from nav (hand a customer one guide) — which one right
 * cannot express.
 *
 * **Why `traverse` and not `list`** (ADR-0212 §2, recorded because the question recurs): it names the
 * mechanism rather than the symptom (denying it on a section blocks direct URLs to everything beneath,
 * not merely a sidebar entry); `list` is grammatically wrong mid-chain (listing is done to a
 * container's children, traversal to a node); and "hide from nav" already exists structurally — the
 * {@see NavProjector} excludes null-`segment` entries as *unplaced*.
 */
enum Right: string
{
    case Traverse = 'traverse';

    case Access = 'access';

    /**
     * The {@see BeamUxEntry} column this right's any-of token list lives on. Right and column are
     * named identically on purpose — there is no mapping table to keep in agreement.
     */
    public function column(): string
    {
        return $this->value;
    }
}
