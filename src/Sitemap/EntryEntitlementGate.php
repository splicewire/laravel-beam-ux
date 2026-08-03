<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **entitlement** half of the {@see EntrySitemapSource} gate: "is this entry
 * PUBLIC (crawlable), or is it gated behind an entitlement?" A sitemap must never
 * leak gated/paid content, so a source yields only what is publicly reachable.
 *
 * BeamUxEntry carries no entitlement column of its own; a host that gates content
 * (e.g. behind `laravel-beam-commerce` plan entitlements) re-binds this port to
 * ask its own authority. The default binding ({@see PublicEntitlementGate}) treats
 * every entry as public — correct for a fully-open site and safe as a floor.
 */
interface EntryEntitlementGate
{
    public function isPublic(BeamUxEntry $entry): bool;
}
