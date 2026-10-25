<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewGrantResolver;
use Symfony\Component\HttpFoundation\Response;

/** Validates a signed bearer link before setting the encrypted, origin-scoped Preview cookie. */
final class PreviewStartController
{
    public function __construct(private PreviewGrantResolver $grants, private PreviewEntryPathResolver $paths) {}

    public function __invoke(Request $request, string $token): Response
    {
        abort_unless($request->hasValidSignature(), Response::HTTP_NOT_FOUND);
        $grant = $this->grants->resolve($token);
        abort_if($grant === null, Response::HTTP_NOT_FOUND);

        $realm = (string) ($request->route()?->defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        $path = $this->paths->pathForEntry($grant->entry_id, $realm, new PreviewContext($grant));
        abort_if($path === null, Response::HTTP_NOT_FOUND);

        $minutes = max(1, min(480, now()->diffInMinutes($grant->expires_at)));
        $cookie = cookie(
            ResolvePreviewContext::COOKIE,
            $token,
            $minutes,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        );

        return redirect($path)
            ->withCookie($cookie)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
