<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The default {@see EntryPublishGate} while charter S6 (workflow marking) is
 * unwired: every entry is treated as published. Re-bound to a real
 * marking-reading gate once S6 lands.
 */
class AlwaysPublishedGate implements EntryPublishGate
{
    public function isPublished(BeamUxEntry $entry): bool
    {
        return true;
    }
}
