<?php

namespace Splicewire\Beam\Ux\Manifest;

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
    public function resolve(BeamUxEntry $entry): array
    {
        return HtmlManifestProfile::manifest() + [
            'x-admits-code' => $this->residency->isCentralResident($entry),
        ];
    }
}
