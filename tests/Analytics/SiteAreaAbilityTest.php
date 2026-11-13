<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Analytics\SiteAreaAbility;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * analytics-area 20 (charter re-scope, 2026-11-12: "fail closed on `site.edit`"): the Site area's ability. In TENANT scope it is
 * the host's tenant site-editing ability, `site.edit`, and a host that does not define it denies (Laravel's default), so the
 * area stays absent and nothing throws. It never falls back to host diagnostics there. In INSTALL scope it is
 * {@see DiagnosticsAbility}, unchanged.
 *
 * Scope is decided as laravel-beam-analytics' `AnalyticsScope::fromTenancy()` decides it: Tenant when beam's
 * {@see TenancyContext} has a current tenant with an id, Install otherwise. A UNIT of the ability alone: it boots without
 * laravel-beam-analytics and never loads SiteArea, so it runs on a vendor whose analytics copy predates the Areas API. The
 * area's own wiring (`#[Area(ability: SiteAreaAbility::NAME)]`) is proved on a host.
 */
class SiteAreaAbilityTest extends TestCase
{
    private function reader(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    /** Inside a tenant's boundary (or not), as beam's TenancyContext answers it. */
    private function inTenant(bool $inside): void
    {
        $this->app->instance(TenancyContext::class, new class($inside) implements TenancyContext
        {
            public function __construct(private readonly bool $inside) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return $this->inside;
            }

            public function currentTenant(): ?object
            {
                return $this->inside ? (object) ['id' => 'acme'] : null;
            }

            public function currentTenantId(): int|string|null
            {
                return $this->inside ? 'acme' : null;
            }

            public function centralConnection(): ?string
            {
                return null;
            }
        });
    }

    /** The host's tenant site-editing ability: reader 1 may edit the site, reader 2 (a member) may not. */
    private function hostDefinesSiteEdit(): void
    {
        Gate::define('site.edit', fn (Authenticatable $user): bool => (int) $user->getAuthIdentifier() === 1);
    }

    /** Host diagnostics (DiagnosticsAbility admits whoever may update entries): reader 3 only. */
    private function diagnosticsAdmit(int $id): void
    {
        Gate::define('beam-ux-entry.update', fn (Authenticatable $user): bool => (int) $user->getAuthIdentifier() === $id);
    }

    private function allows(?User $user): bool
    {
        return $user === null ? Gate::allows(SiteAreaAbility::NAME) : Gate::forUser($user)->allows(SiteAreaAbility::NAME);
    }

    public function test_the_provider_defines_the_ability(): void
    {
        $this->assertTrue(Gate::has(SiteAreaAbility::NAME));
    }

    public function test_in_tenant_scope_a_reader_who_may_edit_the_site_passes_and_a_member_does_not(): void
    {
        $this->inTenant(true);
        $this->hostDefinesSiteEdit();

        $this->assertTrue($this->allows($this->reader(1)), 'site.edit holder');
        $this->assertFalse($this->allows($this->reader(2)), 'member');
    }

    public function test_in_tenant_scope_on_a_host_that_does_not_define_site_edit_every_reader_is_refused_and_nothing_throws(): void
    {
        $this->inTenant(true);
        $this->diagnosticsAdmit(3);
        $this->assertFalse(Gate::has('site.edit'), 'precondition: this host defines no tenant site gate');

        $this->assertFalse($this->allows($this->reader(1)));
        $this->assertFalse($this->allows($this->reader(3)), 'not even a reader host diagnostics admit');
    }

    public function test_in_tenant_scope_host_diagnostics_never_stand_in_for_the_site_gate(): void
    {
        $this->inTenant(true);
        $this->hostDefinesSiteEdit();
        $this->diagnosticsAdmit(3);
        $this->assertTrue(Gate::forUser($this->reader(3))->allows(DiagnosticsAbility::NAME), 'control: reader 3 holds diagnostics');

        $this->assertFalse($this->allows($this->reader(3)));
    }

    public function test_in_install_scope_the_ability_is_host_diagnostics_unchanged(): void
    {
        $this->inTenant(false);
        $this->hostDefinesSiteEdit();
        $this->diagnosticsAdmit(3);

        $this->assertTrue($this->allows($this->reader(3)), 'diagnostics holder');
        $this->assertFalse($this->allows($this->reader(1)), 'site.edit alone does not open the install side');
    }

    public function test_a_guest_is_refused_in_either_scope(): void
    {
        $this->hostDefinesSiteEdit();
        $this->diagnosticsAdmit(3);

        $this->inTenant(true);
        $this->assertFalse($this->allows(null));
        $this->inTenant(false);
        $this->assertFalse($this->allows(null));
    }
}
