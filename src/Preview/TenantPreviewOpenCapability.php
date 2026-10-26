<?php

namespace Splicewire\Beam\Ux\Preview;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxPreviewOpenNonce;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Release\ReleaseLifecycle;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Support\EditorAuthorizer;

/** Mints and atomically consumes a tenant-bound, single-use signed Release Preview handoff. */
final class TenantPreviewOpenCapability
{
    public const TTL_SECONDS = 60;

    public function mint(BeamUxRelease $release, Authenticatable $actor, Request $request): string
    {
        $tenantId = $this->tenantId();
        app(EditorAuthorizer::class)->authorize($actor, $request);
        Gate::forUser($actor)->authorize('view', $release);
        $this->assertPreviewable($release);
        $this->pruneStaleNonces($tenantId);

        $actorToken = app(ReleaseService::class)->actorTokenFor($actor);
        if (! str_starts_with($actorToken, 'tenant-model:')) {
            throw new AuthorizationException('A tenant Preview requires a tenant-bound actor.');
        }

        [$routeName, $routeParameters] = $this->tenantOpenRouteParameters();

        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);
        $nonce = bin2hex(random_bytes(32));
        $record = (new BeamUxPreviewOpenNonce)->setConnection($release->getConnectionName());
        $record->forceFill([
            'nonce_hash' => hash('sha256', $nonce),
            'release_id' => (string) $release->getKey(),
            'actor_token_hash' => hash('sha256', $actorToken),
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAt,
        ])->save();

        return URL::temporarySignedRoute($routeName, $expiresAt, [
            ...$routeParameters,
            'release' => (string) $release->getKey(),
            'actor' => $actorToken,
            'nonce' => $nonce,
        ]);
    }

    public function consume(string $releaseId, string $actorToken, string $nonce): bool
    {
        $tenantId = $this->tenantId();
        if (preg_match('/\A[a-f0-9]{64}\z/', $nonce) !== 1 || ! str_starts_with($actorToken, 'tenant-model:')) {
            return false;
        }

        $now = now();
        $updated = (new BeamUxPreviewOpenNonce)->newQuery()
            ->where('nonce_hash', hash('sha256', $nonce))
            ->where('release_id', $releaseId)
            ->where('actor_token_hash', hash('sha256', $actorToken))
            ->where('tenant_id', $tenantId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now, 'updated_at' => $now]);

        return $updated === 1;
    }

    private function pruneStaleNonces(string $tenantId): void
    {
        $now = now();
        (new BeamUxPreviewOpenNonce)->newQuery()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($now): void {
                $query->whereNotNull('consumed_at')->orWhere('expires_at', '<=', $now);
            })
            ->delete();
    }

    public function authorizeActor(Authenticatable $actor, Request $request, BeamUxRelease $release): void
    {
        app(EditorAuthorizer::class)->authorize($actor, $request);
        Gate::forUser($actor)->authorize('view', $release);
        $this->assertPreviewable($release);
    }

    private function assertPreviewable(BeamUxRelease $release): void
    {
        if ($release->realm !== BeamUxEntry::REALM_SITE
            || ! in_array($release->status, ReleaseLifecycle::OPEN, true)
            || ! $release->members()->whereNotNull('version_id')->exists()) {
            throw (new ModelNotFoundException)->setModel(BeamUxRelease::class, [$release->getKey()]);
        }
    }

    private function tenantId(): string
    {
        $tenancy = app(TenancyContext::class);
        $tenantId = $tenancy->isActive() ? $tenancy->currentTenantId() : null;
        if (! $tenancy->isMultiTenant() || $tenantId === null) {
            throw new AuthorizationException('A tenant Preview requires an initialized tenant.');
        }

        return (string) $tenantId;
    }

    /** Resolve the mounted Site Host route from the tenant's server-owned full host, never from request input. */
    private function tenantOpenRouteParameters(): array
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        $siteHost = is_object($tenant) && method_exists($tenant, 'siteHost') ? $tenant->siteHost() : null;
        if (! is_string($siteHost) || $siteHost === '') {
            throw new ModelNotFoundException('Tenant Site Host is not configured.');
        }

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $domain = $route->getDomain();
            if (! is_string($name) || ! str_ends_with($name, '.site.preview.tenant-open')
                || ! is_string($domain) || preg_match('/^\{tenant\}\.(.+)$/', $domain, $matches) !== 1) {
                continue;
            }

            $suffix = '.'.strtolower($matches[1]);
            $host = strtolower($siteHost);
            if (! str_ends_with($host, $suffix)) {
                continue;
            }

            $tenantLabel = substr($host, 0, -strlen($suffix));
            if ($tenantLabel === '' || str_contains($tenantLabel, '.')) {
                continue;
            }

            return [$name, ['tenant' => $tenantLabel]];
        }

        throw new ModelNotFoundException('Tenant Site Host Preview route is not mounted.');
    }
}
