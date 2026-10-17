<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Splicewire\Beam\Ux\Manifest\ManifestRegistryCheck;
use Splicewire\Beam\Ux\Tests\Manifest\Fixtures\CalloutBlock;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0228 d6 (EP-07a, part b) — the PHP `#[NodeType]` profile and the JS component registry must not
 * drift. The check reports the gap both ways so neither side can silently grow a component the other
 * lacks.
 */
class ManifestRegistryCheckTest extends TestCase
{
    public function test_it_is_in_step_when_php_and_js_match(): void
    {
        $check = new ManifestRegistryCheck;

        $this->assertTrue($check->inStep(['Callout', 'Hero'], ['Hero', 'Callout']));
        $this->assertSame(['missingInJs' => [], 'missingInPhp' => []], $check->drift(['Callout'], ['Callout']));
    }

    public function test_it_reports_a_php_component_the_js_registry_lacks(): void
    {
        $check = new ManifestRegistryCheck;

        $drift = $check->drift(['Callout', 'Hero'], ['Callout']);
        $this->assertSame(['Hero'], $drift['missingInJs']);
        $this->assertSame([], $drift['missingInPhp']);
        $this->assertFalse($check->inStep(['Callout', 'Hero'], ['Callout']));
    }

    public function test_it_reports_a_js_component_with_no_php_node_type(): void
    {
        $drift = (new ManifestRegistryCheck)->drift(['Callout'], ['Callout', 'Ghost']);

        $this->assertSame(['Ghost'], $drift['missingInPhp']);
    }

    public function test_it_reads_node_type_names_from_a_bound_source(): void
    {
        $source = new class implements ManifestNodeSource
        {
            public function profiles(): array { return ['content']; }

            public function nodeClassesFor(string $profile): array { return [CalloutBlock::class]; }

            public function docAdmitsFor(string $profile): ?array { return ['html']; }

            public function marksFor(string $profile): array { return []; }
        };

        $this->assertSame(
            ['missingInJs' => [], 'missingInPhp' => []],
            (new ManifestRegistryCheck)->driftForSource($source, 'content', ['Callout']),
        );
    }
}
