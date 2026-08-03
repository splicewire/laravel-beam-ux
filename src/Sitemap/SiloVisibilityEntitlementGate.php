<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * A Silo-visibility-aware {@see EntryEntitlementGate} (charter S7, ADR-0165 §2 — "BeamSilo's
 * authority/visibility MAY gate"). An entry is PUBLIC only when none of its classification silos is
 * restricted: a silo whose *effective* visibility (walking self → ancestors, the permission-cascade
 * `HasVisibility` chain) sits at a tier other than {@see self::PUBLIC_TIER} marks the entry non-public,
 * so a restricted-silo entry is hidden from the sitemap.
 *
 * **This is a demonstration binding, not the default.** The default {@see PublicEntitlementGate} stays
 * bound (correct + safe floor for a fully-open site); a host that classifies content behind silo
 * visibility re-binds {@see EntryEntitlementGate} to THIS gate (or its own) to have the sitemap honor it.
 * The seam is a PORT — facets never influence the URL, only whether a routable/published entry is
 * *entitled* to appear.
 *
 * An entry with NO silos, or one whose silos are all public (or carry no visibility model at all), stays
 * public — facets are optional, so the absence of classification never gates.
 */
class SiloVisibilityEntitlementGate implements EntryEntitlementGate
{
    /** The visibility tier that counts as publicly reachable; everything else gates. */
    public const PUBLIC_TIER = 'public';

    public function isPublic(BeamUxEntry $entry): bool
    {
        foreach ($entry->silos as $silo) {
            $tier = $this->effectiveTier($silo);

            // A silo with an explicit non-public effective tier gates the entry out of the sitemap.
            if ($tier !== null && $tier !== self::PUBLIC_TIER) {
                return false;
            }
        }

        return true;
    }

    /**
     * The silo's effective visibility tier. Prefers the permission-cascade `HasVisibility` chain
     * (`effectiveVisibility()` — nearest explicit tier walking self → ancestors); falls back to the bare
     * `visibility` attribute for a model that isn't cascade-aware. Null ⇒ no tier set ⇒ not gated here.
     */
    protected function effectiveTier(object $silo): ?string
    {
        if (method_exists($silo, 'effectiveVisibility')) {
            return $silo->effectiveVisibility();
        }

        $tier = $silo->visibility ?? null;

        return $tier === '' ? null : $tier;
    }
}
