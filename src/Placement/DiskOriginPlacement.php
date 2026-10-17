<?php

namespace Splicewire\Beam\Ux\Placement;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;

/** Preserve the imported source address instead of creating a second recognized source. */
final class DiskOriginPlacement implements FilePlacement
{
    public function pathFor(BeamUxEntry $entry): string
    {
        return substr((string) $entry->origin, strlen(Provenance::DISK_PREFIX));
    }
}
