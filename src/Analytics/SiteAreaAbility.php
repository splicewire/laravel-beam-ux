<?php

namespace Splicewire\Beam\Ux\Analytics;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;

/**
 * The Site area's ability (analytics-area 20; charter re-scope 2026-11-12, "fail closed on `site.edit`"), split by scope:
 *
 *  - **Tenant scope:** the host's tenant site-editing ability, `site.edit`, the family's name for it (the flagship defines it;
 *    the tower package already asks it). A host that does not define it denies by Laravel's default, so the area stays absent
 *    there and nothing throws. It never borrows host diagnostics, which show a host's file paths and routing machinery, and
 *    never falls back to the entry policy, which on a central mount asks a gate that cannot take a tenant principal.
 *  - **Install scope** (the operator's side, and an install with no tenancy): {@see DiagnosticsAbility}, unchanged, the
 *    ability Site health and Sitemap health declare.
 *
 * Scope is decided the way laravel-beam-analytics' `AnalyticsScope::fromTenancy()` decides it: Tenant when beam's
 * {@see TenancyContext} has a current tenant with an id, Install otherwise.
 *
 * This only decides who sees the area. Each figure is still the viewer's own read of its page ({@see SiteDestinations}), so a
 * tenant reader who passes `site.edit` but may not read a page draws no figure for it: an honest absence.
 */
final class SiteAreaAbility
{
    public const NAME = 'beam-ux.analytics.site';

    /** The host's tenant site-editing ability. */
    public const TENANT_ABILITY = 'site.edit';

    public static function define(): void
    {
        Gate::define(self::NAME, static function (?Authenticatable $user): bool {
            if ($user === null) {
                return false;
            }
            if (self::inTenant()) {
                return Gate::forUser($user)->allows(self::TENANT_ABILITY);
            }

            return Gate::forUser($user)->allows(DiagnosticsAbility::NAME);
        });
    }

    private static function inTenant(): bool
    {
        $tenancy = app(TenancyContext::class);
        $id = $tenancy->currentTenant() !== null ? $tenancy->currentTenantId() : null;

        return $id !== null && $id !== '';
    }
}
