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
 * whose residency cannot be determined is treated as tenant-owned (code refused). An entry on the DEFAULT
 * connection is central exactly when no tenant is initialized (the flagship reads central entries there;
 * L2 r6 found every central docs page refused as tenant-owned before this).
 */
final class EntryResidency
{
    public function __construct(private TenancyContext $tenancy) {}

    public function isCentralResident(BeamUxEntry $entry): bool
    {
        try {
            $central = $this->tenancy->centralConnection();

            // No central_connection configured: normally a host with no split, whose single connection is central,
            // so a single-tenant host keeps authoring code (ADR-0239 / ux-0227 d1). But null does not promise no
            // tenant (an adapter answers null for an absent or dangling central config while a tenant is live), so
            // it is central only while no tenant is initialized (review-r1 on 09c60e5).
            if ($central === null) {
                return ! $this->tenancy->isActive();
            }

            $own = $entry->getConnectionName();

            // An entry on the DEFAULT connection: an unpinned model's null, or the default's own name, which
            // Eloquent stamps on every model a query hydrates. A host that reads entries on its default
            // connection (the flagship's docs ops, mounted with no tenancy) reaches here for every CENTRAL
            // entry. Which database the default connection is at this moment is exactly whether a tenant is
            // initialized (stancl switches it, or the search_path, per tenant), so: central iff no tenant.
            // Checked FIRST: a schema-per-tenant host may name its default connection as central, and a
            // tenant's entry hydrated on it would otherwise pass as central.
            if ($own === null || $own === config('database.default')) {
                return ! $this->tenancy->isActive();
            }

            // A split host: an entry demonstrably PINNED to the central connection is central — its configured
            // name, or the fabricated 'central' alias ({@see EntryPromoter::CENTRAL_CONNECTION}).
            // Any other connection (a tenant's) is tenant-owned.
            return $own === $central || $own === EntryPromoter::CENTRAL_CONNECTION;
        } catch (\Throwable) {
            // Residency that cannot be determined fails closed to tenant-owned: code is refused.
            return false;
        }
    }
}
