<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\PermissionCascade\Support\PermissionNamer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * api-surface-coherence 147 — `beam-ux-entry` (and the two resources it also backs,
 * `beam-ux-mirror-status` / `beam-ux-sitemap-health`) was one of the eleven resources whose backing
 * model carried NO policy after 135 bound `Hook`'s. The absence read four ways at once: the filters
 * sub-surface fell through, `ParticleController::show()`/`destroy()` (`authorize('view'|'delete')`)
 * denied everyone but a host's Root bypass, the REST write pipeline denied, and the Frame nav hid the
 * seat. `#[UseCascadePolicy]` on the model, bound by THIS package in `packageBooted()`, is one
 * declaration consumed by all four. The token prefix is the `beam_ux_entry` alias the same provider
 * already owned (ADR-0118).
 *
 * This does not replace `EntryAccessGate`: that gate answers the PUBLIC-surface question (who may read
 * a published entry through the site), this policy answers the authoring API's. Gate CLOSED: no
 * `Gate::before(fn () => true)`, the control probe first, spatie's plane booted so a holder is a
 * principal the cascade can admit.
 */
class BeamUxEntryPolicyGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionCascadeServiceProvider::class, PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', PolicyGateUser::class);
        // spatie's teams mode (forced by the cascade unless a host opts out) wants a team id on every
        // grant; this harness has no team, and a null id would fail the composite key.
        $app['config']->set('permission-cascade.manage_spatie_teams', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('beam_ux_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('namespace')->nullable()->index();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('visibility')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->nullable();
        });

        $this->createSpatiePermissionSchema();
    }

    // ── Controls ────────────────────────────────────────────────────────────────────────────────

    public function test_control_the_gate_is_closed_for_a_stranger(): void
    {
        $this->assertFalse(Gate::forUser($this->stranger())->allows('probe-nonexistent-ability'));
    }

    public function test_control_the_cascade_provider_is_booted_not_auto_resolved(): void
    {
        $this->assertSame(app(PermissionNamer::class), app(PermissionNamer::class));
    }

    // ── The declaration ─────────────────────────────────────────────────────────────────────────

    public function test_the_entry_binds_a_cascade_policy_that_answers_view_any(): void
    {
        $policy = Gate::getPolicyFor(BeamUxEntry::class);

        $this->assertInstanceOf(ConfiguredModelPolicy::class, $policy);
        $this->assertTrue(method_exists($policy, 'viewAny'));
    }

    public function test_the_token_prefix_is_the_alias(): void
    {
        $this->assertSame('beam-ux-entry.view', app(PermissionNamer::class)->assemble(BeamUxEntry::class, 'view'));
    }

    public function test_a_stranger_is_denied_every_ability(): void
    {
        $gate = Gate::forUser($this->stranger());
        $entry = $this->entry();

        $this->assertFalse($gate->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse($gate->allows('view', $entry));
        $this->assertFalse($gate->allows('update', $entry));
        $this->assertFalse($gate->allows('delete', $entry));
    }

    public function test_a_holder_of_the_class_family_is_admitted_gate_closed(): void
    {
        $this->useTenantMount();
        $gate = Gate::forUser($this->holder('beam-ux-entry.view', 'beam-ux-entry.update'));
        $entry = $this->entry();

        $this->assertFalse($gate->allows('probe-nonexistent-ability'));
        $this->assertTrue($gate->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue($gate->allows('view', $entry));
        $this->assertTrue($gate->allows('update', $entry));
        $this->assertFalse($gate->allows('delete', $entry));
    }

    public function test_a_central_author_can_list_and_edit_entries_without_cascade_tokens(): void
    {
        $this->useOperatorMount();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'author');
        $author = PolicyGateUser::create(['email' => 'author']);
        $entry = $this->entry();
        $policy = Gate::getPolicyFor(BeamUxEntry::class);

        $this->assertSame(\Splicewire\Beam\Ux\Authorization\BeamUxEntryPolicy::class, app()->make('permission-cascade-policy:'.BeamUxEntry::class)::class);
        $this->assertSame(\Splicewire\Beam\Ux\Authorization\BeamUxEntryPolicy::class, $policy::class);
        $this->assertTrue(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class), 'central list access follows ux.author');
        $this->assertTrue(Gate::forUser($author)->allows('update', $entry), 'central edit access follows ux.author');
    }

    public function test_a_central_reader_without_ux_author_cannot_list_or_edit_entries(): void
    {
        $this->useOperatorMount();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'author');
        $reader = $this->stranger();
        $entry = $this->entry();
        $policy = Gate::getPolicyFor(BeamUxEntry::class);

        $this->assertFalse(Gate::forUser($reader)->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $entry));
    }

    public function test_tenant_entries_keep_the_existing_cascade_authorization(): void
    {
        $this->useTenantMount();
        $member = $this->holder('beam-ux-entry.view', 'beam-ux-entry.update');
        $entry = $this->entry();
        $policy = Gate::getPolicyFor(BeamUxEntry::class);

        $this->assertTrue(Gate::forUser($member)->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue(Gate::forUser($member)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('update', $entry));
    }

    public function test_frame_resolved_tenant_realm_keeps_cascade_access_on_the_generic_socket(): void
    {
        // Tower's generic Frame resource routes carry this server-owned default even when the
        // ParticleOperationController realm default is absent (no ability override map).
        $this->useFrameTenantMount();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'central-author');

        $member = $this->holder('beam-ux-entry.view', 'beam-ux-entry.update');
        $entry = $this->entry();

        $this->assertFalse(Gate::forUser($member)->allows('ux.author'));
        $this->assertTrue(Gate::forUser($member)->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue(Gate::forUser($member)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('viewAny', BeamUxEntry::class));

        // Central authoring authority must not elevate an actor through a tenant Frame mount.
        $author = PolicyGateUser::create(['email' => 'central-author']);
        $this->assertTrue(Gate::forUser($author)->allows('ux.author'));
        $this->assertFalse(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($author)->allows('update', $entry));
    }

    public function test_non_frame_tenant_site_editor_keeps_cascade_access_without_ux_author(): void
    {
        $this->useActiveTenantOnNonFrameRoute();
        Gate::define('site.edit', fn (?PolicyGateUser $user): bool => $user?->email === 'tenant-editor');
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'central-author');

        $editor = $this->holder('beam-ux-entry.view', 'beam-ux-entry.update');
        $editor->email = 'tenant-editor';
        $editor->save();
        $entry = $this->entry();

        $this->assertTrue(Gate::forUser($editor)->allows('site.edit'));
        $this->assertFalse(Gate::forUser($editor)->allows('ux.author'));
        $this->assertTrue(Gate::forUser($editor)->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue(Gate::forUser($editor)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('viewAny', BeamUxEntry::class));
    }

    public function test_non_frame_tenant_does_not_elevate_a_ux_author_only_actor(): void
    {
        $this->useActiveTenantOnNonFrameRoute();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'central-author');

        $author = PolicyGateUser::create(['email' => 'central-author']);
        $entry = $this->entry();

        $this->assertTrue(Gate::forUser($author)->allows('ux.author'));
        $this->assertFalse(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($author)->allows('update', $entry));
    }

    public function test_legacy_unmarked_frame_mount_keeps_cascade_and_ignores_untrusted_operator_markers(): void
    {
        $this->useLegacyFrameMount();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'central-author');
        Gate::define('site.edit', fn (?PolicyGateUser $user): bool => $user?->email === 'tenant-member');

        $member = $this->holder('beam-ux-entry.view', 'beam-ux-entry.update');
        $member->email = 'tenant-member';
        $member->save();
        $entry = $this->entry();

        $this->assertTrue(Gate::forUser($member)->allows('site.edit'));
        $this->assertFalse(Gate::forUser($member)->allows('ux.author'));
        $this->assertTrue(Gate::forUser($member)->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue(Gate::forUser($member)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('viewAny', BeamUxEntry::class));

        // Request-controlled values cannot turn a realm-agnostic route into the central mount.
        request()->query->set('realm', 'operator');
        request()->request->set('realm', 'operator');
        request()->headers->set('X-Realm', 'operator');
        $author = PolicyGateUser::create(['email' => 'central-author']);
        $this->assertTrue(Gate::forUser($author)->allows('ux.author'));
        $this->assertFalse(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($author)->allows('update', $entry));
    }

    public function test_an_operator_marker_uses_ux_author_only_without_active_tenancy(): void
    {
        $this->useOperatorMount();
        Gate::define('ux.author', fn (?PolicyGateUser $user): bool => $user?->email === 'central-author');

        $author = PolicyGateUser::create(['email' => 'central-author']);
        $entry = $this->entry();
        $this->assertTrue(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class));
        $this->assertTrue(Gate::forUser($author)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($this->stranger())->allows('viewAny', BeamUxEntry::class));

        // Even an operator-marked route delegates to the cascade once tenancy is active.
        $this->useActiveTenantOnNonFrameRoute();
        request()->route()->defaults('realm', 'operator');
        $member = $this->holder('beam-ux-entry.view', 'beam-ux-entry.update');
        $this->assertTrue(Gate::forUser($member)->allows('viewAny', BeamUxEntry::class));
        $this->assertFalse(Gate::forUser($author)->allows('viewAny', BeamUxEntry::class));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function entry(): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'namespace' => 'kit.hero']);
    }

    private function useTenantMount(): void
    {
        $route = Route::get('/policy/tenant', fn () => null)
            ->defaults(\Splicewire\Beam\Http\Particle\ParticleOperationController::REALM, 'tenant');
        request()->setRouteResolver(fn () => $route);
    }

    private function useFrameTenantMount(): void
    {
        $route = Route::get('/frame/resources/{resource}', fn () => null)
            ->defaults('realm', 'tenant');
        request()->setRouteResolver(fn () => $route);
    }

    private function useOperatorMount(): void
    {
        $route = Route::get('/api/operator/frame/resources/{resource}', fn () => null)
            ->defaults('realm', 'operator');
        request()->setRouteResolver(fn () => $route);
    }

    private function useLegacyFrameMount(): void
    {
        $route = Route::get('/frame/resources/{resource}', fn () => null);
        request()->setRouteResolver(fn () => $route);
    }

    private function useActiveTenantOnNonFrameRoute(): void
    {
        $this->app->instance(TenancyContext::class, new class implements TenancyContext
        {
            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return (object) ['id' => 'tenant-acme'];
            }

            public function currentTenantId(): int|string|null
            {
                return 'tenant-acme';
            }

            public function centralConnection(): ?string
            {
                return 'central';
            }
        });

        // Deliberately no ParticleOperationController::REALM default: this represents the
        // tenant UX API and editor routes that run after tenancy initialization outside Frame.
        $route = Route::get('/api/tenant/beam-ux-entries', fn () => null);
        request()->setRouteResolver(fn () => $route);
    }

    private function stranger(): PolicyGateUser
    {
        return PolicyGateUser::create(['email' => 'm'.mt_rand().'@beam.test']);
    }

    private function holder(string ...$abilities): PolicyGateUser
    {
        $user = $this->stranger();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createSpatiePermissionSchema(): void
    {
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $t): void {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->index(['model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $t): void {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });
    }
}

class PolicyGateUser extends AuthUser
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
