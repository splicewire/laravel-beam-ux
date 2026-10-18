<?php

namespace Splicewire\Beam\Ux\Manifest;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0228 d7 (EP-07a, part b) — resolves the one Manifest Profile an entry is edited and saved against.
 * The vocabulary is the intrinsic-HTML base ({@see HtmlManifestProfile}); `x-admits-code` is decided by
 * the entry's residency ({@see EntryResidency}, ux-0227 d1): a central-resident entry admits code, a
 * tenant-owned entry does not. The host's placeable `#[NodeType]` components are composed onto the base
 * in the next increment (through a bound ManifestNodeSource); the flagship realm-bind is 07a's later
 * host step.
 */
final class ManifestProfileResolver
{
    public function __construct(private EntryResidency $residency) {}

    /**
     * @return array<string, mixed> a v1 manifest with an `x-admits-code` flag the save check reads.
     */
    public function resolve(BeamUxEntry $entry, string $profile = 'content'): array
    {
        $base = HtmlManifestProfile::manifest();
        $nodes = array_merge($base['nodes'], (new PackagedEditorNodeSource)->manifest($profile)['nodes']);

        // Compose the host's placeable `#[NodeType]` components onto the HTML base through the bound
        // ManifestNodeSource, when the host declares one (the flagship realm-bind is 07a's later host
        // step). Ordered-array composition (ADR-0112): the host profile may NOT redeclare a base name.
        $source = app()->bound(ManifestNodeSource::class) ? app(ManifestNodeSource::class) : null;
        if ($source !== null && in_array($profile, $source->profiles(), true)) {
            $have = array_column($nodes, 'name');
            foreach ((new ManifestBuilder($source))->build($profile)['nodes'] as $node) {
                if (! in_array($node['name'], $have, true)) {
                    $nodes[] = $node;
                }
            }
        }

        return [
            'profile' => $base['profile'],
            'version' => 1,
            'doc' => $base['doc'],
            'nodes' => $nodes,
            'marks' => $base['marks'],
            'x-prose' => $base['x-prose'],
            'x-admits-code' => $this->residency->isCentralResident($entry),
        ];
    }
}
