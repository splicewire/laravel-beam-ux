<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * ADR-0227 d1 (EP-07a, part b): x-admits-code keys on WHERE THE ENTRY LIVES, not on who is editing or the request's
 * realm. `EntryResidency::isCentralResident()` is the one helper the resolver and the inspector share, and it FAILS
 * CLOSED: residency it cannot determine is tenant-owned, so the save refuses code.
 *
 * L2 r6 (orch-lead 01:15Z/01:20Z): the flagship reads its central docs entries on its DEFAULT connection (its entry ops
 * mount with no tenancy), and Eloquent stamps every model a query hydrates with that connection's NAME. The old rule
 * admitted only the configured central name or the 'central' alias, so every central entry was "tenant-owned" and no
 * central author could save source. On the default connection, the discriminator is whether a tenant is initialized.
 *
 * Every hydrated case below is a REAL query: rows are inserted, then read back through Eloquent, on the default
 * connection or on a named one sharing its PDO (the same in-memory database under another connection name).
 */
class EntryResidencyTest extends TestCase
{
    private const CENTRAL = 'central_db';

    private const TENANT = 'tenant_acme';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable();
            $table->string('slug');
            $table->string('type');
            $table->string('format')->default('tsx');
            $table->string('realm')->default('site');
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $default = config('database.default');
        foreach ([self::CENTRAL, self::TENANT, 'central'] as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$default}")]);
            DB::purge($name);
            DB::connection($name)->setPdo(DB::connection($default)->getPdo());
        }
    }

    private function tenancy(?string $central, bool $active = false, bool $throws = false): TenancyContext
    {
        return new class($central, $active, $throws) implements TenancyContext
        {
            public function __construct(private ?string $central, private bool $active, private bool $throws) {}

            public function isMultiTenant(): bool { return $this->central !== null; }

            public function isActive(): bool
            {
                if ($this->throws) {
                    throw new RuntimeException('tenancy probe failed');
                }

                return $this->active;
            }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return $this->active ? 'acme' : null; }

            public function centralConnection(): ?string { return $this->central; }
        };
    }

    /** A tsx page row, inserted directly so no model event is part of the subject. */
    private function seedRow(): string
    {
        $id = (string) Str::uuid();
        DB::table('beam_ux_entries')->insert([
            'id' => $id, 'slug' => 'docs/quickstart', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'segment' => '/docs/quickstart', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** The entry as a QUERY hydrates it on the default connection: Eloquent stamps the default's name. */
    private function hydratedOnDefault(): BeamUxEntry
    {
        $entry = BeamUxEntry::query()->findOrFail($this->seedRow());
        $this->assertSame(config('database.default'), $entry->getConnectionName(), 'a hydrated model carries the query connection name, not null');

        return $entry;
    }

    private function hydratedOn(string $connection): BeamUxEntry
    {
        $entry = BeamUxEntry::on($connection)->findOrFail($this->seedRow());
        $this->assertSame($connection, $entry->getConnectionName());

        return $entry;
    }

    public function test_a_single_tenant_host_is_central_so_starters_keep_authoring_code(): void
    {
        // No central_connection configured => no split => the one connection IS central.
        $residency = new EntryResidency($this->tenancy(null));

        $this->assertTrue($residency->isCentralResident($this->hydratedOnDefault()));
        $this->assertTrue($residency->isCentralResident(new BeamUxEntry));
    }

    public function test_no_central_connection_does_not_admit_code_while_a_tenant_is_initialized(): void
    {
        // review-r1 on 09c60e5: an adapter may answer null for an absent or dangling central config while a tenant
        // is live. Null is "no split" only when no tenant is initialized.
        $residency = new EntryResidency($this->tenancy(null, active: true));

        $this->assertFalse($residency->isCentralResident($this->hydratedOnDefault()));
        $this->assertFalse($residency->isCentralResident(new BeamUxEntry));
    }

    public function test_a_default_connection_entry_is_central_while_no_tenant_is_initialized(): void
    {
        // The flagship's case: a split host reading a central docs entry on its default connection.
        $residency = new EntryResidency($this->tenancy(self::CENTRAL, active: false));

        $this->assertTrue($residency->isCentralResident($this->hydratedOnDefault()), 'hydrated with the default name');
        $this->assertTrue($residency->isCentralResident(new BeamUxEntry), 'an unpinned model (null connection)');
    }

    public function test_a_default_connection_entry_is_tenant_owned_while_a_tenant_is_initialized(): void
    {
        // Schema-per-tenant: a tenant switches the search_path on the SAME connection, so the default connection is
        // that tenant's while it is initialized.
        $residency = new EntryResidency($this->tenancy(self::CENTRAL, active: true));

        $this->assertFalse($residency->isCentralResident($this->hydratedOnDefault()));
        $this->assertFalse($residency->isCentralResident(new BeamUxEntry));
    }

    public function test_a_host_naming_its_default_connection_as_central_still_refuses_a_tenants_entry(): void
    {
        // Schema-per-tenant with central_connection = the default name: a tenant entry hydrated on it carries the
        // CENTRAL name, and must not pass as central while that tenant is initialized.
        $default = config('database.default');

        $this->assertFalse((new EntryResidency($this->tenancy($default, active: true)))->isCentralResident($this->hydratedOnDefault()));
        $this->assertTrue((new EntryResidency($this->tenancy($default, active: false)))->isCentralResident($this->hydratedOnDefault()));
    }

    public function test_an_entry_pinned_to_the_central_connection_is_central(): void
    {
        foreach ([false, true] as $active) {
            $residency = new EntryResidency($this->tenancy(self::CENTRAL, active: $active));

            $this->assertTrue($residency->isCentralResident($this->hydratedOn(self::CENTRAL)), 'the configured central connection');
            $this->assertTrue($residency->isCentralResident($this->hydratedOn('central')), "the fabricated 'central' alias");
        }
    }

    public function test_an_entry_pinned_to_a_tenant_connection_is_tenant_owned_regardless_of_the_actor(): void
    {
        // The operator-realm case: an operator editing a tenant-owned entry. Residency ignores the actor.
        foreach ([false, true] as $active) {
            $residency = new EntryResidency($this->tenancy(self::CENTRAL, active: $active));

            $this->assertFalse($residency->isCentralResident($this->hydratedOn(self::TENANT)));
        }
    }

    public function test_a_tenancy_probe_that_throws_fails_closed_to_tenant_owned(): void
    {
        $residency = new EntryResidency($this->tenancy(self::CENTRAL, throws: true));

        $this->assertFalse($residency->isCentralResident($this->hydratedOnDefault()));
    }

    public function test_the_save_guard_admits_a_central_docs_source_save_and_refuses_it_in_a_tenant(): void
    {
        // End to end through the container's own guard (what save-draft / save-body call): a central tsx docs page
        // read on the default connection, saved with a SOURCE body.
        $source = ['source' => 'export default function Page() { return <p>Quickstart</p>; }'];

        foreach ([false => true, true => false] as $active => $admitted) {
            $this->app->instance(TenancyContext::class, $this->tenancy(self::CENTRAL, active: (bool) $active));
            foreach ([EntryResidency::class, ManifestProfileResolver::class, CanvasBodyGuard::class] as $abstract) {
                $this->app->forgetInstance($abstract);
            }
            $entry = $this->hydratedOnDefault();

            try {
                $this->app->make(CanvasBodyGuard::class)->assertAdmitted($entry, $source);
                $this->assertTrue($admitted, 'a tenant-initialized save of source was admitted');
            } catch (ValidationException $refused) {
                $this->assertFalse($admitted, 'a central docs source save was refused: '.json_encode($refused->errors()));
            }
        }
    }
}
