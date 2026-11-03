<?php

namespace Splicewire\Beam\Ux\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * This package adopts the package-tier registry docs check over the whole of `src/`: every declared
 * root is covered under `docs/registries/`, and every page's generated facts block is byte-identical
 * to a fresh render of its `#[IsRegistry]` declaration.
 *
 * The coverage count is asserted too, so an inactive check (no `docs/registries/` directory) or a
 * scan that finds nothing cannot pass by reporting no failures.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    public function test_every_declared_root_is_documented_with_its_facts_in_step(): void
    {
        $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertCount(3, $report->coverage, 'the three beam.ux.* roots: '.implode(', ', array_keys($report->coverage)));
    }
}
