<?php

namespace Splicewire\Beam\Ux\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewGrantResolver;
use Splicewire\Beam\Ux\Preview\ReleasePreviewLinks;
use Symfony\Component\HttpFoundation\Response;

/** Resolve the encrypted preview cookie into a fresh, request-scoped grant context. */
final class ResolvePreviewContext
{
    public const COOKIE = 'beam_ux_preview';

    public const VIEWER_COOKIE = 'beam_ux_preview_viewer';

    /** `live` while the bar shows the live site at the same URL (ADR-0232 d13); the Preview waits, still verified. */
    public const MODE_COOKIE = 'beam_ux_preview_mode';

    /** The request attribute that holds a verified Preview while the live site is shown. */
    public const LIVE = 'beam.ux.preview.live';

    public function __construct(private PreviewGrantResolver $grants, private ?ReleasePreviewLinks $links = null) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);
        if (is_string($token) && $token !== '') {
            // A Release's Preview Link (ADR-0232 d8) carries the link itself, checked again on every request.
            $grant = ReleasePreviewLinks::isCredential($token)
                ? ($this->links ?? app(ReleasePreviewLinks::class))->resolve($token)
                : $this->grants->resolve($token);
            if ($grant !== null) {
                $viewer = $request->cookie(self::VIEWER_COOKIE) === 'me'
                    ? PreviewContext::centralViewer($request)
                    : null;
                $request->attributes->set($request->cookie(self::MODE_COOKIE) === 'live' ? self::LIVE : 'beam.ux.preview',
                    new PreviewContext($grant, $viewer));
            }
        }

        $response = $next($request);

        if ($request->attributes->get('beam.ux.preview') instanceof PreviewContext || $request->attributes->get(self::LIVE) instanceof PreviewContext) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
