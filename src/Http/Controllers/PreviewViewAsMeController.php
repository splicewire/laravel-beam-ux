<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Symfony\Component\HttpFoundation\Response;

/** Opts an authenticated central-site reviewer into their own reads for this Preview. */
final class PreviewViewAsMeController
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->attributes->get('beam.ux.preview') instanceof PreviewContext, Response::HTTP_NOT_FOUND);
        abort_if(PreviewContext::centralViewer($request) === null, Response::HTTP_NOT_FOUND);

        $path = PreviewContext::returnPath($request->query('path', '/'));
        abort_if($path === null, Response::HTTP_BAD_REQUEST);

        $preview = $request->attributes->get('beam.ux.preview');
        $minutes = max(1, min(480, now()->diffInMinutes($preview->grant->expires_at)));
        $cookie = cookie(ResolvePreviewContext::VIEWER_COOKIE, 'me', $minutes, '/', null,
            $request->isSecure(), true, false, 'lax');

        return redirect($path)->withCookie($cookie)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
