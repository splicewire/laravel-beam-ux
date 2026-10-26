<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Ux\Http\Controllers\TenantPreviewReleaseController;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
use Splicewire\Beam\Ux\Models\BeamUxPreviewOpenNonce;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Particle\ReleasePreviewOpenOp;
use Splicewire\Beam\Ux\Preview\TenantPreviewOpenCapability;
use Symfony\Component\HttpFoundation\Response;

class TenantPreviewOpenCapabilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionCascadeServiceProvider::class, PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web.provider', 'users');
        $app['config']->set('auth.providers.users.model', TenantPreviewCapabilityUser::class);
        $app['config']->set('permission.teams', true);
        $app['config']->set('permission.team_foreign_key', 'team_id');
        $app['config']->set('permission-cascade.manage_spatie_teams', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('beam_ux_preview_open_nonces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('nonce_hash', 64)->unique();
            $table->uuid('release_id')->index();
            $table->char('actor_token_hash', 64);
            $table->string('tenant_id')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::create('beam_ux_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('realm');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('beam_ux_release_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('release_id')->index();
            $table->uuid('entry_id');
            $table->uuid('version_id')->nullable();
            $table->timestamps();
        });
        Schema::create('beam_ux_preview_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->uuid('entry_id');
            $table->uuid('version_id');
            $table->uuid('release_id')->nullable();
            $table->string('created_by');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('beam_ux_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('realms')->nullable();
            $table->string('type');
            $table->string('segment')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->softDeletes();
        });
    }

    public function test_capability_nonce_is_tenant_bound_expiring_and_single_use(): void
    {
        $release = new BeamUxRelease;
        $release->setConnection(config('database.default'));
        $release->id = (string) Str::uuid();
        $actorToken = 'tenant-model:tenant-a:actor-class:tenant-connection:42';
        $nonce = str_repeat('a', 64);
        $this->setTenant('tenant-a');
        $this->seedNonce($release, $actorToken, $nonce, now()->addMinute());

        $capability = app(TenantPreviewOpenCapability::class);
        $this->assertTrue($capability->consume((string) $release->getKey(), $actorToken, $nonce));
        $this->assertFalse($capability->consume((string) $release->getKey(), $actorToken, $nonce), 'A nonce cannot be replayed.');
    }

    public function test_capability_nonce_fails_closed_for_expiry_tenant_or_actor_mismatch(): void
    {
        $release = new BeamUxRelease;
        $release->setConnection(config('database.default'));
        $release->id = (string) Str::uuid();
        $actorToken = 'tenant-model:tenant-a:actor-class:tenant-connection:42';
        $this->setTenant('tenant-a');
        $expired = str_repeat('b', 64);
        $wrongTenant = str_repeat('c', 64);
        $wrongActor = str_repeat('d', 64);
        $this->seedNonce($release, $actorToken, $expired, now()->subSecond());
        $this->seedNonce($release, $actorToken, $wrongTenant, now()->addMinute());
        $this->seedNonce($release, $actorToken, $wrongActor, now()->addMinute());

        $capability = app(TenantPreviewOpenCapability::class);
        $this->assertFalse($capability->consume((string) $release->getKey(), $actorToken, $expired));
        $this->setTenant('tenant-b');
        $this->assertFalse($capability->consume((string) $release->getKey(), $actorToken, $wrongTenant), 'A different tenant cannot consume the capability.');
        $this->setTenant('tenant-a');
        $this->assertFalse($capability->consume((string) $release->getKey(), 'tenant-model:tenant-a:other:connection:42', $wrongActor));
        $this->assertFalse($capability->consume((string) $release->getKey(), $actorToken, 'not-a-nonce'));
    }

    public function test_authorization_rejects_a_non_site_release_without_leaking_an_internal_error(): void
    {
        Gate::before(fn () => true);
        $request = Request::create('/tenant/beam-ux-releases/example/preview-open');
        $request->setRouteResolver(function () {
            return (new \Illuminate\Routing\Route(['POST'], '/tenant/beam-ux-releases/{id}/preview-open', fn () => null))
                ->defaults(ParticleOperationController::REALM, 'tenant')
                ->defaults(ParticleOperationController::ABILITY_OVERRIDES, ['ux.author' => 'site.edit']);
        });

        $release = new BeamUxRelease;
        $release->forceFill(['id' => (string) Str::uuid(), 'realm' => 'operator', 'status' => 'draft']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(TenantPreviewOpenCapability::class)->authorizeActor(new User, $request, $release);
    }

    public function test_signed_site_host_open_consumes_nonce_before_resolving_the_actor(): void
    {
        $this->setTenant('tenant-a');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['app.url' => 'http://acme.site.example.test']);
        Route::domain('{tenant}.site.example.test')->get(
            '_beam/preview/tenant-open/{release}',
            TenantPreviewReleaseController::class,
        )->middleware('signed')->name('test.site.preview.tenant-open');

        $releaseId = (string) Str::uuid();
        $actorToken = 'tenant-model:'.base64_encode('tenant-a')
            .':'.base64_encode('MissingTenantActor')
            .':'.base64_encode('tenant')
            .':'.base64_encode('42');
        $nonce = str_repeat('e', 64);
        $release = new BeamUxRelease;
        $release->setConnection(config('database.default'));
        $release->id = $releaseId;
        $this->seedNonce($release, $actorToken, $nonce, now()->addMinute());

        $url = URL::temporarySignedRoute('test.site.preview.tenant-open', now()->addMinute(), [
            'tenant' => 'acme',
            'release' => $releaseId,
            'actor' => $actorToken,
            'nonce' => $nonce,
        ]);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)), 'The generated signed URL is valid before the HTTP dispatch.');

        $matched = false;
        $requestSignatureValid = false;
        app('events')->listen(RouteMatched::class, function (RouteMatched $event) use (&$matched, &$requestSignatureValid): void {
            $matched = $event->route->getName() === 'test.site.preview.tenant-open';
            $requestSignatureValid = URL::hasValidSignature($event->request);
        });
        $response = $this->get($url);
        $response->assertNotFound();
        $this->assertTrue($matched, 'The signed Site Host route was reached.');
        $this->assertTrue($requestSignatureValid, 'The actual HTTP request retains a valid Site Host signature.');
        $record = \Illuminate\Support\Facades\DB::table('beam_ux_preview_open_nonces')
            ->where('nonce_hash', hash('sha256', $nonce))
            ->first();
        $this->assertNotNull($record);
        $this->assertSame($releaseId, $record->release_id);
        $this->assertSame('tenant-a', $record->tenant_id);
        $this->assertTrue(hash_equals(hash('sha256', $actorToken), $record->actor_token_hash));
        $this->assertNotNull(
            $record->consumed_at,
            'A signed capability is consumed before any actor resolution or authorization attempt.',
        );
    }

    public function test_site_host_http_mint_and_open_use_the_spatie_team_context_and_reject_replay(): void
    {
        $tenantId = 'tenant-a';
        $teamId = 42;
        $this->setTenant($tenantId);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['app.url' => 'https://acme.site.example.test']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);

        $permission = Permission::findOrCreate('site.edit', 'web');
        $role = Role::findOrCreate('tenant-admin', 'web');
        $role->givePermissionTo($permission);
        $actor = TenantPreviewCapabilityUser::query()->create(['name' => 'Tenant Admin']);
        $actor->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::define('entitlement:site.edit', fn ($user): bool => $user->hasPermissionTo('site.edit', 'web'));

        $releaseId = (string) Str::uuid();
        $entryId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $release = new BeamUxRelease;
        $release->forceFill([
            'id' => $releaseId,
            'realm' => BeamUxEntry::REALM_SITE,
            'status' => 'draft',
        ])->save();
        $member = new BeamUxReleaseMember;
        $member->forceFill([
            'id' => (string) Str::uuid(),
            'release_id' => $releaseId,
            'entry_id' => $entryId,
            'version_id' => $versionId,
        ])->save();

        app('router')->aliasMiddleware('test.preview-team', TenantPreviewTeamContext::class);
        Route::domain('{tenant}.site.example.test')->middleware('test.preview-team')->post(
            '/api/v1/tenant/beam-ux-releases/{release}/preview-open',
            function (Request $request) {
                $request->route()->defaults(ParticleOperationController::REALM, 'tenant');
                $request->route()->defaults(ParticleOperationController::ABILITY_OVERRIDES, ['ux.author' => 'site.edit']);

                return response()->json(ReleasePreviewOpenOp::handle(
                    BeamUxRelease::query()->findOrFail((string) $request->route('release')),
                    $request,
                    $request->user(),
                ));
            },
        )->name('test.tenant.release.preview-open');

        Route::domain('{tenant}.site.example.test')->middleware(['signed', 'test.preview-team'])->get(
            '/_beam/preview/tenant-open/{release}',
            TenantPreviewReleaseController::class,
        )->defaults(ParticleOperationController::REALM, 'tenant')
            ->defaults(ParticleOperationController::ABILITY_OVERRIDES, ['ux.author' => 'site.edit'])
            ->name('test.site.preview.tenant-open');

        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, new class
        {
            public function siteHost(): string { return 'acme.site.example.test'; }
        });

        $this->actingAs($actor, 'web');
        $deniedActor = TenantPreviewCapabilityUser::query()->create(['name' => 'Tenant Member without site.edit']);
        $this->actingAs($deniedActor, 'web')
            ->postJson('https://acme.site.example.test/api/v1/tenant/beam-ux-releases/'.$releaseId.'/preview-open')
            ->assertForbidden();

        $this->actingAs($actor, 'web');
        $mint = $this->postJson('https://acme.site.example.test/api/v1/tenant/beam-ux-releases/'.$releaseId.'/preview-open');
        $mint->assertOk()->assertJsonStructure(['url']);
        $openUrl = $mint->json('url');
        $this->assertIsString($openUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($openUrl)));

        $parsed = parse_url($openUrl);
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('nonce', $query);
        $this->assertArrayHasKey('actor', $query);

        $tamperedRelease = (string) Str::uuid();
        $this->get(str_replace($releaseId, $tamperedRelease, $openUrl))->assertForbidden();

        $open = $this->get($openUrl);
        $open->assertRedirect('/');
        $nonceRecord = \Illuminate\Support\Facades\DB::table('beam_ux_preview_open_nonces')
            ->where('release_id', $releaseId)
            ->first();
        $this->assertNotNull($nonceRecord);
        $this->assertNotNull($nonceRecord->consumed_at);
        $this->assertSame(hash('sha256', (string) $query['nonce']), $nonceRecord->nonce_hash);
        $this->assertSame(hash('sha256', (string) $query['actor']), $nonceRecord->actor_token_hash);

        $this->get($openUrl)->assertNotFound();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('beam_ux_preview_grants')
            ->where('release_id', $releaseId)->count());

        $this->setTenant('tenant-b');
        $foreignActorToken = app(\Splicewire\Beam\Ux\Release\ReleaseService::class)->actorTokenFor($actor);
        $this->setTenant($tenantId);
        $foreignNonce = str_repeat('f', 64);
        $this->seedNonce($release, $foreignActorToken, $foreignNonce, now()->addMinute());
        $foreignActorUrl = URL::temporarySignedRoute('test.site.preview.tenant-open', now()->addMinute(), [
            'tenant' => 'acme',
            'release' => $releaseId,
            'actor' => $foreignActorToken,
            'nonce' => $foreignNonce,
        ]);
        $this->get($foreignActorUrl)->assertNotFound();
        $foreignNonceRecord = \Illuminate\Support\Facades\DB::table('beam_ux_preview_open_nonces')
            ->where('nonce_hash', hash('sha256', $foreignNonce))
            ->first();
        $this->assertNotNull($foreignNonceRecord?->consumed_at);
    }

    private function seedNonce(BeamUxRelease $release, string $actorToken, string $nonce, mixed $expiresAt): void
    {
        (new BeamUxPreviewOpenNonce)->setConnection($release->getConnectionName())->forceFill([
            'nonce_hash' => hash('sha256', $nonce),
            'release_id' => (string) $release->getKey(),
            'actor_token_hash' => hash('sha256', $actorToken),
            'tenant_id' => app(TenancyContext::class)->currentTenantId(),
            'expires_at' => $expiresAt,
        ])->save();
    }

    private function setTenant(string $tenantId): void
    {
        $this->app->instance(TenancyContext::class, new class($tenantId) implements TenancyContext
        {
            public function __construct(private string $tenantId) {}

            public function isMultiTenant(): bool { return true; }

            public function isActive(): bool { return true; }

            public function currentTenant(): ?object { return null; }

            public function currentTenantId(): int|string|null { return $this->tenantId; }

            public function centralConnection(): ?string { return 'central'; }
        });
    }
}

class TenantPreviewCapabilityUser extends User
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];
}

class TenantPreviewTeamContext
{
    public function handle(Request $request, \Closure $next): Response
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(42);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $next($request);
    }
}
