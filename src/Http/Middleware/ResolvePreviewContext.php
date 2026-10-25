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
                $request->attributes->set('beam.ux.preview', new PreviewContext($grant, $viewer));
            }
        }

        $response = $next($request);

        if ($request->attributes->get('beam.ux.preview') instanceof PreviewContext) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
