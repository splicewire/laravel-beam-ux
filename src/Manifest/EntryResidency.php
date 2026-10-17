<?php

namespace Splicewire\Beam\Ux\Manifest;

use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Lifecycle\EntryPromoter;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0227 d1 (EP-07a, part b) — the blessed test for whether a BeamUxEntry is central-resident, which
 * decides its Manifest Profile's `x-admits-code`. It keys on WHERE THE ENTRY LIVES (its own database
 * connection), never on who is editing or the request's realm: an operator editing a tenant-owned entry
 * must not be able to write code into a tenant's site. The resolver and (later, EP-07b) the inspector
 * both read this one helper, so the save and the palette never disagree.
 *
 * It asks laravel-beam's {@see TenancyContext} for the central connection and **fails closed**: an entry
 * whose residency cannot be determined is treated as tenant-owned (code refused).
 */
final class EntryResidency
{
    public function __construct(private TenancyContext $tenancy) {}

    public function isCentralResident(BeamUxEntry $entry): bool
    {
        $central = $this->tenancy->centralConnection();

        // No central_connection configured means the host runs no split, so its single connection is
        // central and a single-tenant host keeps authoring code (ADR-0239 / ux-0227 d1).
        if ($central === null) {
            return true;
        }

        $own = $entry->getConnectionName();

        // A split host: the entry is central only when it demonstrably lives on the central connection
        // — its configured name, or the fabricated 'central' alias ({@see EntryPromoter::CENTRAL_CONNECTION}).
        // A tenant schema, or an undeterminable (null/default) connection, FAILS CLOSED to tenant-owned.
        return $own !== null
            && ($own === $central || $own === EntryPromoter::CENTRAL_CONNECTION);
    }
}
