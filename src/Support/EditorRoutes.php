<?php

namespace Splicewire\Beam\Ux\Support;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Ux\Particle\EntryMakeEditableOp;
use Splicewire\Beam\Ux\Particle\ResolvePageEntryRefOp;

/** Called by the host inside its auth/verified group; every operation also declares ux.author. */
final class EditorRoutes
{
    public static function mount(string $uri = 'beam-ux-entries', string $releasesUri = 'beam-ux-releases'): void
    {
        // NoSubject must precede any {id} route; no hidden package route registration.
        Particle::ops($uri, 'beam-ux-entry', [ResolvePageEntryRefOp::class, EntryMakeEditableOp::class]);
        foreach (['body', 'save-body', 'save-draft', 'publish', 'versions', 'restore', 'clear-body', 'print-source', 'read-source', 'canvas-bindings', 'component-capabilities', 'create-component', 'duplicate-component', 'create-release', 'preview'] as $op) {
            Particle::ops($uri, 'beam-ux-entry', $op);
        }
        Particle::mount($releasesUri, 'beam-ux-release')->only(['index', 'show'])->ops(['command', 'readiness', 'changes']);
        // ADR-0236 d14: the Redirects seat's resource; the service checks the rights publishing takes.
        Particle::mount('beam-ux-redirects', 'beam-ux-redirect');
        Particle::mount('beam-ux-release-files', 'beam-ux-release-files')->only(['index', 'show'])->ops(['commit-now', 'inspect-file', 'take-file-draft', 'take-all-drafts', 'restore-missing-file', 'remirror-file']);
    }
}
