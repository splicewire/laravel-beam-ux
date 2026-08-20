<?php

namespace Splicewire\Beam\Ux\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shipped {@see EntryRenderer} — an Inertia response, the same shape the incumbent
 * `GuardedGuideController` returns (ADR-0209 §6).
 *
 * Inertia is a soft dependency: beam-ux does not require it, so this class checks for it and says
 * exactly what to do rather than fataling on an undefined class three frames deep. A host without
 * Inertia binds its own {@see EntryRenderer} instead — that is what the port is for.
 */
class InertiaEntryRenderer implements EntryRenderer
{
    public function render(string $page, array $props): Response
    {
        if (! function_exists('inertia') && ! class_exists(\Inertia\Inertia::class)) {
            throw new RuntimeException(
                'beam-ux\'s public entry renderer returns an Inertia response, but inertiajs/inertia-laravel '.
                'is not installed. Install it, or bind your own '.EntryRenderer::class.' implementation.'
            );
        }

        return \Inertia\Inertia::render($page, $props)->toResponse(request());
    }
}
