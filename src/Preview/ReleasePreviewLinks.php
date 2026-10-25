<?php

namespace Splicewire\Beam\Ux\Preview;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Release\ReleaseLifecycle;

/**
 * A Release's **Preview Links** (ADR-0232 d8). A link names one Release and when it expires, signed with a key derived
 * from the install's key and the secret the Release holds; nothing is stored per link. Opening one starts the same
 * Preview cookie a version preview does (at most 8 hours), carrying the link itself, and every request checks it again:
 * the Release still open, links still on, the secret unchanged, the link unexpired. Rotating the secret, turning links
 * off or publishing therefore ends every link and every Preview a link opened, at once.
 *
 * A link previews its own Release and nothing else: it resolves to a Release-scoped grant that is never saved, signs
 * nobody in, and is read only where the Preview middleware already runs (the public renderer and its artifacts).
 */
final class ReleasePreviewLinks
{
    /** The Release states a Preview may show (as {@see PreviewGrantService::resolve()}); a published one has ended. */
    public const OPEN = ReleaseLifecycle::OPEN;

    private const PREFIX = 'link:';

    /** A new secret for a Release: turning links on, or rotating them. */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** A link to preview the Release, valid for `beam.ux.preview.link_days` (7) days; null while it can have none. */
    public function url(BeamUxRelease $release, ?CarbonInterface $expiresAt = null): ?string
    {
        $name = config('beam.ux.route_name', 'beam.ux.').'site.preview.link';
        if (! $this->usable($release) || ! Route::has($name)) {
            return null;
        }
        $expires = ($expiresAt ?? now()->addDays((int) config('beam.ux.preview.link_days', 7)))->getTimestamp();

        return route($name, ['release' => $release->getKey(), 'expires' => $expires, 'signature' => $this->sign($release, $expires)]);
    }

    /** The Release a link names, when the link is genuine, unexpired and the Release can still be previewed. */
    public function verify(string $releaseId, int $expires, string $signature): ?BeamUxRelease
    {
        if ($expires <= now()->getTimestamp() || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1) {
            return null;
        }
        $release = BeamUxRelease::query()->find($releaseId);
        if ($release === null || ! $this->usable($release) || ! hash_equals($this->sign($release, $expires), $signature)) {
            return null;
        }

        return $release;
    }

    /** What the Preview cookie carries for an opened link: the link itself, so each request verifies it again. */
    public function credential(BeamUxRelease $release, int $expires, string $signature): string
    {
        return self::PREFIX.$release->getKey().'.'.$expires.'.'.$signature;
    }

    public static function isCredential(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * The Release-scoped grant an opened link stands for, built in memory and never saved, or null once the link has
     * ended. {@see PreviewContext} reads a Release grant through its members, as it does for a stored one.
     */
    public function resolve(string $credential): ?BeamUxPreviewGrant
    {
        if (preg_match('/\A'.preg_quote(self::PREFIX, '/').'([0-9a-zA-Z-]+)\.(\d+)\.([a-f0-9]{64})\z/', $credential, $parts) !== 1) {
            return null;
        }
        $release = $this->verify($parts[1], (int) $parts[2], $parts[3]);
        if ($release === null) {
            return null;
        }
        $grant = new BeamUxPreviewGrant;
        $grant->setConnection($release->getConnectionName());
        $grant->forceFill([
            'release_id' => $release->getKey(),
            'created_by' => 'preview-link',
            'expires_at' => CarbonImmutable::createFromTimestamp((int) $parts[2]),
        ]);
        $grant->setRelation('release', $release);

        return $grant;
    }

    private function usable(BeamUxRelease $release): bool
    {
        return (bool) $release->getAttribute('preview_links_enabled')
            && is_string($release->getAttribute('preview_link_secret')) && $release->getAttribute('preview_link_secret') !== ''
            && in_array($release->status, self::OPEN, true);
    }

    private function sign(BeamUxRelease $release, int $expires): string
    {
        $key = hash_hmac('sha256', (string) $release->getAttribute('preview_link_secret'), (string) config('app.key'));

        return hash_hmac('sha256', 'beam-ux-preview-link|'.$release->getKey().'|'.$expires, $key);
    }
}
