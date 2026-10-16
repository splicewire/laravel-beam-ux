<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0227 d1 (EP-07a, part b) — x-admits-code keys on WHERE THE ENTRY LIVES, not on who is editing
 * or the request's realm (orch-lead 2026-10-16 23:27Z). `EntryResidency::isCentralResident()` is the
 * blessed helper the resolver and (later) the inspector share: it asks laravel-beam's TenancyContext
 * whether the entry's own connection is the central one, and FAILS CLOSED — an entry whose residency
 * cannot be determined is treated as tenant-owned (so the save refuses code).
 */
class EntryResidencyTest extends TestCase
{
    private function tenancy(?string $central): TenancyContext
    {
        return new class($central) implements TenancyContext
        {
            public function __construct(private ?string $central) {}

            public function isMultiTenant(): bool { return $this->central !== null; }

            public function isActive(): bool { return false; }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return null; }

            public function centralConnection(): ?string { return $this->central; }
        };
    }

    private function entry(?string $connection): BeamUxEntry
    {
        $entry = new BeamUxEntry;
        $entry->setConnection($connection);

        return $entry;
    }

    public function test_a_single_tenant_host_is_central_so_starters_keep_authoring_code(): void
    {
        // No central_connection configured => no split => the one connection IS central.
        $residency = new EntryResidency($this->tenancy(null));

        $this->assertTrue($residency->isCentralResident($this->entry('testing')));
        $this->assertTrue($residency->isCentralResident($this->entry(null)));
    }

    public function test_an_entry_on_the_central_connection_is_central(): void
    {
        $residency = new EntryResidency($this->tenancy('central_db'));

        $this->assertTrue($residency->isCentralResident($this->entry('central_db')), 'the configured central connection');
        $this->assertTrue($residency->isCentralResident($this->entry('central')), "the fabricated 'central' alias");
    }

    public function test_an_entry_in_a_tenant_schema_is_not_central_regardless_of_the_actor(): void
    {
        // The operator-realm case: an operator editing a tenant-owned entry. Residency ignores the
        // actor — the entry's own connection is the tenant's, so code is refused.
        $residency = new EntryResidency($this->tenancy('central_db'));

        $this->assertFalse($residency->isCentralResident($this->entry('tenant_acme')));
    }

    public function test_an_undeterminable_connection_fails_closed_to_tenant(): void
    {
        // A split host where the entry carries no own connection (the ambiguous default): refuse.
        $residency = new EntryResidency($this->tenancy('central_db'));

        $this->assertFalse($residency->isCentralResident($this->entry(null)));
    }
}
