<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;

/**
 * ADR-0228 d6 (EP-07a, part b) — the check that keeps the JS side in step. A host's placeable
 * components exist twice: as PHP `#[NodeType]` Block classes (the Manifest Profile, which types the
 * save) and as React components the canvas registers (which draws the palette). If the two drift, a
 * tenant could place a component the save refuses, or the save could admit one the canvas cannot draw.
 * This compares the two name sets both ways so the drift is loud, not silent.
 *
 * The JS registry's representation (the component names the canvas registers) is supplied by the host
 * — the flagship's JS build emits it; the comparison here is the blockdoc-agnostic half.
 */
final class ManifestRegistryCheck
{
    /**
     * @param  list<string>  $phpComponents  the `#[NodeType]` component names the profile declares
     * @param  list<string>  $jsRegistry     the component names the JS canvas registers
     * @return array{missingInJs: list<string>, missingInPhp: list<string>}
     */
    public function drift(array $phpComponents, array $jsRegistry): array
    {
        return [
            'missingInJs' => array_values(array_diff($phpComponents, $jsRegistry)),
            'missingInPhp' => array_values(array_diff($jsRegistry, $phpComponents)),
        ];
    }

    /**
     * @param  list<string>  $phpComponents
     * @param  list<string>  $jsRegistry
     */
    public function inStep(array $phpComponents, array $jsRegistry): bool
    {
        $drift = $this->drift($phpComponents, $jsRegistry);

        return $drift['missingInJs'] === [] && $drift['missingInPhp'] === [];
    }

    /**
     * Read a source's `#[NodeType]` component names (via {@see ManifestBuilder}) and compare them with
     * the host's JS registry.
     *
     * @param  list<string>  $jsRegistry
     * @return array{missingInJs: list<string>, missingInPhp: list<string>}
     */
    public function driftForSource(ManifestNodeSource $source, string $profile, array $jsRegistry): array
    {
        $php = array_column((new ManifestBuilder($source))->build($profile)['nodes'], 'name');

        return $this->drift($php, $jsRegistry);
    }
}
