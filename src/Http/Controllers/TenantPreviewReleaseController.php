<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewGrantService;
use Splicewire\Beam\Ux\Preview\TenantPreviewOpenCapability;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Symfony\Component\HttpFoundation\Response;

/** Opens a tenant Release Preview only through its signed, actor-bound, single-use capability. */
final class TenantPreviewReleaseController
{
    public function __construct(private PreviewGrantService $grants, private PreviewEntryPathResolver $paths) {}

    public function __invoke(Request $request): Response
    {
        $release = $request->route('release');
        $actorToken = $request->query('actor');
        $nonce = $request->query('nonce');
        $this->redactCapabilityFromRequestLog($request);
        abort_unless(is_string($release) && is_string($actorToken) && is_string($nonce), Response::HTTP_NOT_FOUND);

        $capability = app(TenantPreviewOpenCapability::class);
        abort_unless($capability->consume($release, $actorToken, $nonce), Response::HTTP_NOT_FOUND);

        $service = app(ReleaseService::class);
        $actor = $service->resolveActorToken($actorToken);
        abort_unless($actor instanceof Authenticatable, Response::HTTP_NOT_FOUND);

        $found = BeamUxRelease::query()->findOrFail($release);
        $capability->authorizeActor($actor, $request, $found);

        $members = $found->members()->whereNotNull('version_id')->orderBy('entry_id')->get();
        abort_if($members->isEmpty(), Response::HTTP_NOT_FOUND);

        $realm = (string) ($request->route()?->defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        $land = $members->first();
        $grant = $this->grants->issue(
            (string) $land->entry_id,
            (string) $land->version_id,
            (string) $found->getKey(),
            (string) $actor->getAuthIdentifier(),
            now()->addHours(8),
        );
        $path = '/';
        foreach ($members as $member) {
            if (($resolved = $this->paths->pathForEntry((string) $member->entry_id, $realm, new PreviewContext($grant->record))) !== null) {
                $path = $resolved;
                break;
            }
        }

        return redirect($path)
            ->withCookie(cookie(ResolvePreviewContext::COOKIE, $grant->token, 480, '/', null, $request->isSecure(), true, false, 'lax'))
            ->withCookie(cookie()->forget(ResolvePreviewContext::VIEWER_COOKIE))
            ->withCookie(cookie()->forget(ResolvePreviewContext::MODE_COOKIE))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function redactCapabilityFromRequestLog(Request $request): void
    {
        // The signed middleware has already verified these values. Keep them available to this
        // controller, but remove the query string from the generic request-log payload before
        // its terminable middleware captures it.
        $logData = $request->attributes->get('requestLogData');
        if (is_object($logData) && property_exists($logData, 'url')) {
            $logData->url = $request->url();
        }
    }
}
