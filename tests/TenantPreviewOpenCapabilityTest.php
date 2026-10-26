<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxPreviewOpenNonce;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\TenantPreviewOpenCapability;

class TenantPreviewOpenCapabilityTest extends TestCase
{
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
        $this->assertTrue($capability->consume($release, $actorToken, $nonce));
        $this->assertFalse($capability->consume($release, $actorToken, $nonce), 'A nonce cannot be replayed.');
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
        $this->assertFalse($capability->consume($release, $actorToken, $expired));
        $this->setTenant('tenant-b');
        $this->assertFalse($capability->consume($release, $actorToken, $wrongTenant), 'A different tenant cannot consume the capability.');
        $this->setTenant('tenant-a');
        $this->assertFalse($capability->consume($release, 'tenant-model:tenant-a:other:connection:42', $wrongActor));
        $this->assertFalse($capability->consume($release, $actorToken, 'not-a-nonce'));
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
