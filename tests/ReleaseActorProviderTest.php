<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Localization\CodedAuthorizationException;
use Splicewire\Beam\Ux\Release\ReleaseService;

/**
 * config-sections 10b (lead ruling 10:15Z): a Release actor token resolves for a caller authenticated by a guard that has
 * NO user provider. Sanctum's guard is that case: Authenticate:sanctum makes it the default guard, so the flagship's
 * operator-tier Release command answered release_author_unresolvable for every caller, Root included (found by the
 * flagship's OperatorConfigReleaseTest). ReleaseService::userProvider() now falls back to the web guard's provider,
 * read from auth config.
 *
 * The provider-less guard here is a request guard (Auth::viaRequest), the shape of Sanctum's, with
 * `auth.defaults.provider` unset as a stock Laravel auth config leaves it.
 */
class ReleaseActorProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        config([
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => User::class],
            'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
            'auth.defaults.provider' => null,
        ]);
    }

    /** Authenticate the request through a guard with no user provider and make it the default, as Authenticate:sanctum does. */
    private function useProviderlessGuard(): void
    {
        Auth::viaRequest('providerless', fn () => User::query()->find(7));
        config(['auth.guards.providerless' => ['driver' => 'providerless']]);
        Auth::shouldUse('providerless');
        $this->assertNull(config('auth.guards.providerless.provider'), 'precondition: the default guard has no provider');
    }

    private function service(): ReleaseService
    {
        return app(ReleaseService::class);
    }

    public function test_a_central_actor_token_resolves_when_the_default_guard_has_no_provider(): void
    {
        $this->useProviderlessGuard();
        $token = $this->service()->actorTokenFor(User::query()->findOrFail(7));
        $this->assertSame('user:7', $token);

        $actor = $this->service()->resolveActorToken($token);

        $this->assertInstanceOf(User::class, $actor);
        $this->assertSame(7, (int) $actor->getAuthIdentifier());
    }

    public function test_an_unknown_or_malformed_token_is_still_unresolvable(): void
    {
        $this->useProviderlessGuard();

        $this->assertNull($this->service()->resolveActorToken('user:999'), 'a user that does not exist');
        $this->assertNull($this->service()->resolveActorToken('nobody'), 'a token of no known shape');
        $this->assertNull($this->service()->resolveActorToken(null));

        $actor = new \ReflectionMethod(ReleaseService::class, 'actor');
        try {
            $actor->invoke($this->service(), 'user:999');
            $this->fail('an unknown actor must stay release_author_unresolvable');
        } catch (CodedAuthorizationException $refused) {
            $this->assertSame('release_author_unresolvable', $refused->errorCode());
        }
    }

    public function test_the_tenant_actor_path_is_unchanged(): void
    {
        // A tenant-model token resolves only while THAT tenant is initialized, never through the user provider.
        $this->useProviderlessGuard();
        $token = 'tenant-model:'.base64_encode('tenant-1').':'.base64_encode(User::class).':'.base64_encode(config('database.default')).':'.base64_encode('7');

        $this->assertNull($this->service()->resolveActorToken($token), 'no tenancy active: unresolvable, as before');

        $tenancy = \Mockery::mock(\Splicewire\Beam\Realm\Contracts\TenancyContext::class)->shouldIgnoreMissing();
        $tenancy->shouldReceive('isActive')->andReturnTrue();
        $tenancy->shouldReceive('isMultiTenant')->andReturnTrue();
        $tenancy->shouldReceive('currentTenantId')->andReturn('tenant-1');
        $this->app->instance(\Splicewire\Beam\Realm\Contracts\TenancyContext::class, $tenancy);

        $actor = $this->service()->resolveActorToken($token);
        $this->assertInstanceOf(User::class, $actor, 'that tenant active: it resolves, as before');

        $other = 'tenant-model:'.base64_encode('tenant-2').':'.base64_encode(User::class).':'.base64_encode(config('database.default')).':'.base64_encode('7');
        $this->assertNull($this->service()->resolveActorToken($other), 'another tenant: unresolvable, as before');
    }
}
