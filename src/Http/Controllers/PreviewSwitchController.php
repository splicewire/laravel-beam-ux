<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bar's switch (ADR-0232 d13): the same URL, in the Preview or on the live site. `to=live` sets the mode cookie, so
 * pages render live while the Preview stays verified behind the bar; `to=preview` clears it. Needs a Preview, and
 * returns only to a path on this site.
 */
final class PreviewSwitchController
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->attributes->get('beam.ux.preview') instanceof PreviewContext
            || $request->attributes->get(ResolvePreviewContext::LIVE) instanceof PreviewContext, Response::HTTP_NOT_FOUND);
        $to = $request->query('to');
        $path = PreviewContext::returnPath($request->query('path', '/'));
        abort_if(! in_array($to, ['live', 'preview'], true) || $path === null, Response::HTTP_BAD_REQUEST);

        return redirect($path)
            ->withCookie($to === 'live'
                ? cookie(ResolvePreviewContext::MODE_COOKIE, 'live', 480, '/', null, $request->isSecure(), true, false, 'lax')
                : cookie()->forget(ResolvePreviewContext::MODE_COOKIE))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
