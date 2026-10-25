<?php

namespace Splicewire\Beam\Ux\Preview;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

/** Mints and resolves short-lived opaque Preview grants without persisting their bearer token. */
final class PreviewGrantService implements PreviewGrantResolver
{
    public function issue(
        string $entryId,
        string $versionId,
        ?string $releaseId,
        string $createdBy,
        CarbonInterface $expiresAt,
    ): PreviewGrant {
        if (! $expiresAt->isFuture() || $expiresAt->greaterThan(now()->addHours(8))) {
            throw new \InvalidArgumentException('A Preview grant must expire within eight hours.');
        }

        $token = bin2hex(random_bytes(32));

        $record = new BeamUxPreviewGrant;
        $record->forceFill([
            'token_hash' => hash('sha256', $token),
            'entry_id' => $entryId,
            'version_id' => $versionId,
            'release_id' => $releaseId,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
        ])->save();

        return new PreviewGrant($token, $record);
    }

    public function signedUrl(string $routeName, PreviewGrant $grant, CarbonInterface $linkExpiresAt): string
    {
        if (! $linkExpiresAt->isFuture() || $linkExpiresAt->greaterThan(now()->addMinutes(10))
            || $linkExpiresAt->greaterThan($grant->record->expires_at)) {
            throw new \InvalidArgumentException('A Preview link must expire within ten minutes and before its grant.');
        }

        return URL::temporarySignedRoute($routeName, $linkExpiresAt, ['token' => $grant->token]);
    }

    public function resolve(string $token): ?BeamUxPreviewGrant
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }

        $grant = BeamUxPreviewGrant::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($grant === null) {
            return null;
        }

        if ($grant->release_id !== null) {
            $release = BeamUxRelease::query()->find($grant->release_id);
            if ($release === null || ! in_array($release->status, ['draft', 'in_review', 'approved', 'scheduled'], true)
                || ! $release->members()->where('entry_id', $grant->entry_id)->where('version_id', $grant->version_id)->exists()) {
                return null;
            }
        }

        return $grant;
    }

    public function revoke(BeamUxPreviewGrant $grant): void
    {
        $grant->forceFill(['revoked_at' => now()])->save();
    }
}
