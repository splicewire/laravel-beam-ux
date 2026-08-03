<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The default {@see EntryEntitlementGate}: every entry is public. Correct for a
 * fully-open site; a gating host re-binds the port to consult its own entitlement
 * authority (e.g. laravel-beam-commerce).
 */
class PublicEntitlementGate implements EntryEntitlementGate
{
    public function isPublic(BeamUxEntry $entry): bool
    {
        return true;
    }
}
