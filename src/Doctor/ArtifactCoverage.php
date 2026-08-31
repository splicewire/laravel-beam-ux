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
        public int $total = 0,
        public int $covered = 0,
        public int $structural = 0,
        public int $pointer = 0,
    ) {}

    public function excluded(): int
    {
        return $this->structural + $this->pointer;
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

        if ($this->excluded() === 0) {
            return "{$sentence}; 0 excluded.";
        }

        return "{$sentence}; {$this->excluded()} excluded ({$this->reasons()}).";
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
