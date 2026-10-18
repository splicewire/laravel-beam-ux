<?php

namespace Splicewire\Beam\Ux\Support;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Ux\Particle\EntryMakeEditableOp;

/** Called by the host inside its auth/verified group; every operation also declares ux.author. */
final class EditorRoutes
{
    public static function mount(string $uri = 'beam-ux-entries'): void
    {
        // NoSubject must precede any {id} route; no hidden package route registration.
        Particle::ops($uri, 'beam-ux-entry', [EntryMakeEditableOp::class]);
        foreach (['body', 'save-body', 'save-draft', 'publish', 'versions', 'restore', 'clear-body', 'print-source', 'read-source'] as $op) {
            Particle::ops($uri, 'beam-ux-entry', $op);
        }
    }
}
