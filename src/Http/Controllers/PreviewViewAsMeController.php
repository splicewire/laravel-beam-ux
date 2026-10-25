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

        $path = $request->query('path', '/');
        abort_unless(is_string($path)
            && strlen($path) <= 4096
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '\\')
            && parse_url($path, PHP_URL_HOST) === null
            && ! preg_match('/[\x00-\x20]/', $path), Response::HTTP_BAD_REQUEST);

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
