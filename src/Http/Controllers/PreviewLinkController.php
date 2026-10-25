<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a Release's Preview Link (ADR-0232 d8): verifies it, then starts the Preview cookie as a version preview's
 * signed link does, at most 8 hours (d16) and never past the link's own expiry, carrying the link so each request checks
 * it again. Lands on the Release's first member page, else the site's root. Every refusal is the same 404.
 */
final class PreviewLinkController
{
    public function __construct(private ReleasePreviewLinks $links, private PreviewEntryPathResolver $paths) {}

    public function __invoke(Request $request, string $release): Response
    {
        $expires = (int) $request->query('expires', '0');
        $signature = (string) $request->query('signature', '');
        $found = $this->links->verify($release, $expires, $signature);
        abort_if($found === null, Response::HTTP_NOT_FOUND);

        $credential = $this->links->credential($found, $expires, $signature);
        $grant = $this->links->resolve($credential);
        abort_if($grant === null, Response::HTTP_NOT_FOUND);
        $realm = (string) ($request->route()?->defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        $context = new PreviewContext($grant);
        $path = '/';
        foreach ($found->members()->whereNotNull('version_id')->orderBy('entry_id')->pluck('entry_id') as $entryId) {
            if (($resolved = $this->paths->pathForEntry((string) $entryId, $realm, $context)) !== null) {
                $path = $resolved;
                break;
            }
        }
        $minutes = max(1, min(480, (int) ceil(($expires - now()->getTimestamp()) / 60)));

        return redirect($path)
            ->withCookie(cookie(ResolvePreviewContext::COOKIE, $credential, $minutes, '/', null, $request->isSecure(), true, false, 'lax'))
            ->withCookie(cookie()->forget(ResolvePreviewContext::VIEWER_COOKIE))
            ->withCookie(cookie()->forget(ResolvePreviewContext::MODE_COOKIE))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
