<?php

namespace Splicewire\Beam\Ux\Doctor;

/**
 * {@see \Splicewire\Beam\Ux\Disk\UpdateFromNewer}'s **denominator** — how many registered entries the
 * reconcile actually examined against a file, and how many it could not pair with one.
 *
 * This is {@see ArtifactCoverage} applied one seam over (beam-docs-satellite ticket 58, the same shape
 * ticket 53 fixed in `BeamUxArtifactAudit`). The batch iterates `BeamUxEntry::all()` and reports
 * `N updated · M unchanged` — and *unchanged* silently fuses two opposite outcomes: **the file was
 * there and was not newer**, and **there was no file at all**. Measured 2026-08-31 at
 * `~/Herd/splicewire-app`: 26 leaves authored flat at `docs/<track>/<slug>.mdx` while
 * {@see \Splicewire\Beam\Ux\Placement\DefaultPlacement} resolves the mirror to
 * `docs/<track>/page/<slug>.mdx`, so the command examined 33 entries, paired 2, and rendered
 * `Direction disk-to-record · 0 updated · 33 unchanged.` — byte-identical to a run where everything was
 * genuinely current. This estate's signature defect: an instrument reporting success by not running.
 *
 * ⚠️ **`misplaced` is a CANDIDATE, never a repair.** It means only that a file with the entry's
 * basename exists somewhere under the scanned root. A basename can collide across namespaces, so the
 * reason string says "candidate" and this class never names the file, never proposes a move, and never
 * lets the command act on the guess. Reach before precision — a wrong *label* on a real finding is
 * acceptable here, a fabricated finding is not.
 *
 * ⚠️ **The three-way split was refused.** `unmatched` deliberately does NOT separate "no counterpart
 * exists" from "the counterpart is outside the scanned root": nothing inside the command can tell those
 * apart, and a bucket that cannot be measured is a bucket that reads as thorough exactly where it is
 * weakest.
 */
class ReconcileCoverage
{
    public function __construct(
        public int $total,
        public int $updated,
        public int $current,
        public int $misplaced,
        public int $unmatched,
    ) {}

    /** Entries the batch paired with a file at their own placement path — the coverage numerator. */
    public function matched(): int
    {
        return $this->updated + $this->current;
    }

    /**
     * The reconciliation residual: `total` minus every bucket. Zero is the only correct value, and it is
     * stated rather than trusted.
     *
     * ⚠️ **This counter exists because {@see ArtifactCoverage} shipped without it and immediately grew
     * the exact defect it was built to repair** — a `total` that incremented on a branch none of the
     * buckets did, read out as a confident coverage figure with nothing reconciling the arithmetic. It
     * was added by a follow-up commit; adding it here in v1 is the whole point of copying the pattern.
     *
     * ⚠️ **The return is SIGNED, and deliberately so.** The first version of this method wrapped the
     * subtraction in `max(0, …)`, which is the estate's signature defect landing on the self-check built
     * to prevent it: a clamp can only ever see an UNDER-count, so an entry landing in two buckets — or a
     * future branch incrementing one without a `continue` — read out as `0`, byte-identical to
     * "everything reconciles". The defect that prompted this class was an under-count, so the clamp
     * covered exactly the one direction that had already bitten and silently blessed the other. Callers
     * must test `!== 0` (or {@see reconciles()}), never `> 0`.
     */
    public function unaccounted(): int
    {
        return $this->total - $this->matched() - $this->misplaced - $this->unmatched;
    }

    /** Whether the buckets sum to `total` — the arithmetic this class exists to state rather than assume. */
    public function reconciles(): bool
    {
        return $this->unaccounted() === 0;
    }

    /**
     * The coverage sentence, rendered by the operator command in place of a bare verdict. Always states
     * the denominator — `0 updated · 33 unchanged` is exactly the reading this class exists to stop.
     */
    public function sentence(): string
    {
        if ($this->total === 0) {
            return 'no registered entries exist, so nothing was reconciled.';
        }

        $sentence = "{$this->matched()} of {$this->total} registered entrie(s) had a file at their ".
            "placement path ({$this->updated} updated; {$this->current} already current)";

        $sentence .= $this->unpaired() === 0
            ? '; 0 without one'
            : "; {$this->unpaired()} without one ({$this->reasons()})";

        if (! $this->reconciles()) {
            $residual = $this->unaccounted();

            $sentence .= $residual > 0
                ? "; ⚠️ {$residual} counted in no bucket — this command's own arithmetic does not ".
                    'reconcile, so read the coverage as a lower bound'
                : '; ⚠️ '.abs($residual)." counted in more than one bucket — this command's own arithmetic ".
                    'does not reconcile, so read the coverage as a lower bound on the FINDINGS and an '.
                    'upper bound on the coverage';
        }

        return "{$sentence}.";
    }

    /** Entries with no file at their own placement path — a finding, not an exclusion. */
    public function unpaired(): int
    {
        return $this->misplaced + $this->unmatched;
    }

    /** Why each unpaired entry went unexamined — never a count on its own, which reads as a defect. */
    private function reasons(): string
    {
        $reasons = [];

        if ($this->misplaced > 0) {
            $reasons[] = "{$this->misplaced} with a same-basename candidate elsewhere under the scanned ".
                'root — a basename can collide across namespaces, so this is a candidate, not a verdict';
        }

        if ($this->unmatched > 0) {
            $reasons[] = "{$this->unmatched} with no same-basename file anywhere under the scanned root";
        }

        return implode('; ', $reasons);
    }
}
