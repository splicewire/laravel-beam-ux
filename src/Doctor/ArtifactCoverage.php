<?php

namespace Splicewire\Beam\Ux\Doctor;

/**
 * {@see BeamUxArtifactAudit}'s **denominator** — how many `page` entries the artifact check actually
 * covered, and how many its two skip rules legitimately put out of reach.
 *
 * This exists because the audit was honestly green and unreadable at the same time (beam-docs-satellite
 * 51, ruling 3). Its skip rules are correct — a structural node heads a containment subtree and no URL
 * resolves to it; a nav pointer has no body and never had one — so an entry they exclude is genuinely
 * not a finding. The cost is that a host where they exclude EVERYTHING emits the same unqualified pass
 * as a host where they exclude nothing. Measured 2026-08-31: `~/Herd/audiostud` (0 of 18 covered, all
 * 18 excluded) and `~/Herd/splicewire` (26 of 31) both read `INFO ✅ every routable page has an
 * artifact compiled from its current body`, and the difference between them was invisible.
 *
 * ⚠️ **The exclusions are NOT findings and must not become any**, which is the narrow line this class
 * walks. Three separate repairs in this package (`CompileEntriesCommand`, this audit, and
 * `BeamUxRouteShadowAudit`) were each about one reader re-learning that a structural node is not
 * content, and each shipped a blocking error naming rows that were working correctly. What changed here
 * is the *reporting*, never the remit: the numbers ride on the finding the audit was already emitting.
 */
class ArtifactCoverage
{
    public function __construct(
        public int $total,
        public int $covered,
        public int $structural,
        public int $pointer,
        public int $unsupported,
    ) {}

    public function excluded(): int
    {
        return $this->structural + $this->pointer;
    }

    /**
     * Rows this class counted in `total` and put in no bucket — which should be none, and is stated
     * rather than trusted.
     *
     * ⚠️ **This counter exists because the first version of this class shipped the exact defect it was
     * built to repair.** `$total` incremented for every page row while the unsupported-format branch
     * incremented nothing, so a host with one such page read `26 of 32 …; 5 excluded` — one entry in
     * no bucket, and nothing reconciling the arithmetic. The estate's rule is that an instrument
     * enumerating its known blind spots and not its unknown ones reads as thorough exactly where it is
     * weakest; a bucket added today can drift the same way tomorrow, so the sum is checked, not
     * assumed.
     */
    public function unaccounted(): int
    {
        return max(0, $this->total - $this->covered - $this->excluded() - $this->unsupported);
    }

    /**
     * The coverage sentence, prefixed to the finding's own detail. Always states the denominator — a
     * bare "everything is compiled" is exactly the reading this class exists to stop.
     */
    public function sentence(): string
    {
        if ($this->total === 0) {
            return 'no `page` entries exist, so nothing was checked.';
        }

        $sentence = "{$this->covered} of {$this->total} routable page(s) have an artifact compiled from ".
            'their current body';

        $sentence .= $this->excluded() === 0
            ? '; 0 excluded'
            : "; {$this->excluded()} excluded ({$this->reasons()})";

        // Reported separately from `excluded`, because it is not an exclusion: an unsupported-format
        // page is a FINDING this audit already fails on, and folding it into the excluded count would
        // read as "legitimately out of reach" for the one bucket that is not.
        if ($this->unsupported > 0) {
            $sentence .= "; {$this->unsupported} in a format the bound compiler does not handle";
        }

        if ($this->unaccounted() > 0) {
            $sentence .= "; ⚠️ {$this->unaccounted()} counted in no bucket — this audit's own arithmetic ".
                'does not reconcile, so read the coverage as a lower bound';
        }

        return "{$sentence}.";
    }

    /** Why each excluded entry is out of reach — never a count on its own, which reads as a defect. */
    private function reasons(): string
    {
        $reasons = [];

        if ($this->structural > 0) {
            $reasons[] = "{$this->structural} structural node(s) with no segment — no URL resolves to them";
        }

        if ($this->pointer > 0) {
            $reasons[] = "{$this->pointer} nav pointer(s) with no particle — no body was ever written";
        }

        return implode('; ', $reasons);
    }
}
