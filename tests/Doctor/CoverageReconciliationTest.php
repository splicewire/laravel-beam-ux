<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Ux\Doctor\ArtifactCoverage;
use Splicewire\Beam\Ux\Doctor\ReconcileCoverage;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * The two coverage classes' **self-check**, exercised in both directions.
 *
 * ⚠️ **Why this file exists at all.** `ArtifactCoverage::unaccounted()` and `ReconcileCoverage::
 * unaccounted()` both shipped as `max(0, $total - …)`, and the clamp is the estate's signature defect
 * landing on the one method built to prevent it: it can only ever detect an UNDER-count. A row counted
 * in two buckets, or a future branch that increments one without a `continue`, produced a residual of
 * `-1` that the clamp reported as `0` — byte-identical to "everything reconciles". The under-count is
 * the direction that had already bitten; the over-count was blessed by construction.
 *
 * Worse, before this file the positive branch had never been OBSERVED. The only assertion in the suite
 * naming it was an `assertStringNotContainsString('lower bound', …)` on a reconciling fixture
 * (`RegisterAndUpdateFromDiskTest::553`) — so a self-check installed to stop "an instrument reporting
 * success by not running" had itself only ever been seen succeeding. Every case here asserts the clause
 * IS emitted, and each was verified RED (an assertion failure, not an error) against the clamped code.
 */
class CoverageReconciliationTest extends TestCase
{
    /**
     * @return array<string, array{ReconcileCoverage, int}>
     */
    public static function reconcileResiduals(): array
    {
        return [
            // total 10; matched 4 + misplaced 1 + unmatched 2 = 7 → one branch increments `total` and
            // no bucket. The direction the clamp could already see.
            'under-count' => [new ReconcileCoverage(total: 10, updated: 3, current: 1, misplaced: 1, unmatched: 2), 3],

            // total 10; matched 6 + misplaced 3 + unmatched 3 = 12 → two entries in two buckets each.
            // The direction the clamp reported as a clean reconcile.
            'over-count' => [new ReconcileCoverage(total: 10, updated: 4, current: 2, misplaced: 3, unmatched: 3), -2],
        ];
    }

    #[DataProvider('reconcileResiduals')]
    public function test_reconcile_coverage_states_a_residual_in_either_direction(
        ReconcileCoverage $coverage,
        int $expected,
    ): void {
        $this->assertSame($expected, $coverage->unaccounted());
        $this->assertFalse($coverage->reconciles());

        $sentence = $coverage->sentence();

        $this->assertStringContainsString('lower bound', $sentence, "the residual must be stated: {$sentence}");
        $this->assertStringContainsString('does not reconcile', $sentence, $sentence);
        $this->assertStringContainsString((string) abs($expected), $sentence, $sentence);
    }

    public function test_reconcile_coverage_stays_silent_when_the_buckets_sum(): void
    {
        $coverage = new ReconcileCoverage(total: 10, updated: 4, current: 2, misplaced: 3, unmatched: 1);

        $this->assertSame(0, $coverage->unaccounted());
        $this->assertTrue($coverage->reconciles());
        $this->assertStringNotContainsString('lower bound', $coverage->sentence());
    }

    /**
     * @return array<string, array{ArtifactCoverage, int}>
     */
    public static function artifactResiduals(): array
    {
        return [
            // total 32; covered 26 + excluded 4 + unsupported 1 = 31.
            'under-count' => [new ArtifactCoverage(total: 32, covered: 26, structural: 3, pointer: 1, unsupported: 1), 1],

            // total 32; covered 26 + excluded 6 + unsupported 2 = 34 — two rows double-bucketed.
            'over-count' => [new ArtifactCoverage(total: 32, covered: 26, structural: 4, pointer: 2, unsupported: 2), -2],
        ];
    }

    #[DataProvider('artifactResiduals')]
    public function test_artifact_coverage_states_a_residual_in_either_direction(
        ArtifactCoverage $coverage,
        int $expected,
    ): void {
        $this->assertSame($expected, $coverage->unaccounted());
        $this->assertFalse($coverage->reconciles());

        $sentence = $coverage->sentence();

        $this->assertStringContainsString('lower bound', $sentence, "the residual must be stated: {$sentence}");
        $this->assertStringContainsString('does not reconcile', $sentence, $sentence);
        $this->assertStringContainsString((string) abs($expected), $sentence, $sentence);
    }

    public function test_artifact_coverage_stays_silent_when_the_buckets_sum(): void
    {
        $coverage = new ArtifactCoverage(total: 32, covered: 26, structural: 3, pointer: 2, unsupported: 1);

        $this->assertSame(0, $coverage->unaccounted());
        $this->assertTrue($coverage->reconciles());
        $this->assertStringNotContainsString('lower bound', $coverage->sentence());
    }
}
