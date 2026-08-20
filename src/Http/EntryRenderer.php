<?php

namespace Splicewire\Beam\Ux\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * How a resolved entry becomes a response (ADR-0209 §6). The shipped default is
 * {@see InertiaEntryRenderer}, mirroring the incumbent `GuardedGuideController`.
 *
 * It is a port because beam-ux does not require Inertia — it is not in `composer.json`, and a headless
 * or API-only host that installs beam-ux for its authoring model has no business being forced into an
 * SPA adapter. Server-rendering the body to HTML in PHP was rejected outright: ADR-0122 requires that
 * guarded content never appear in server-rendered HTML, so it would force two divergent render paths.
 *
 * The **page name is not this port's to choose** — no beam package ships a rendered page, so it arrives
 * as a mount-time argument from the host and travels through as a parameter.
 */
interface EntryRenderer
{
    /**
     * @param  string  $page  the host-supplied page component name, passed to the macro at mount time
     * @param  array<string, mixed>  $props
     */
    public function render(string $page, array $props): Response;
}
