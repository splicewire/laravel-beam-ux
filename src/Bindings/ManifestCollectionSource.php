<?php

namespace Splicewire\Beam\Ux\Bindings;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** The host explicitly offers Collections in its existing Manifest Profile. No discovery fallback. */
interface ManifestCollectionSource
{
    /** @return list<CollectionSource> */
    public function collections(BeamUxEntry $entry, string $profile): array;
}
