<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Symfony\Component\HttpFoundation\Response;

final class PreviewExitController
{
    public function __invoke(): Response
    {
        return redirect('/')->withCookie(cookie()->forget(ResolvePreviewContext::COOKIE));
    }
}
