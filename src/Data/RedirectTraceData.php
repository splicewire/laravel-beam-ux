<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * Where one Redirect leads a guest, from the renderer's own walk ({@see \Splicewire\Beam\Ux\Redirect\RedirectResolver::follow()}).
 * The Site map (owner-review-20261102 research 37) reads it for a page's "Old addresses" and for "Needs attention":
 * a loop, a walk past the step bound, a dead end and a gone target fail a guest; an answered chain does not (D-37-g (b)).
 */
#[TypeScript, Title('Redirect trace')]
final class RedirectTraceData extends BeamData
{
    /** @param list<string> $steps */
    public function __construct(
        public string $id,
        public string $realm,
        #[Description('The path the Redirect starts from.')]
        public string $path,
        #[Description('`exact`, or `prefix` for the path and everything below it.')]
        public string $match,
        public string $targetKind,
        public bool $permanent,
        public string $origin,
        #[Description('Why the walk stopped: `answered`, `loop`, `too-long`, `dead-end`, `gone`, `denied` (readers only), or `shadowed` (a page answers the path, so the Redirect never runs).')]
        public string $end,
        #[Description('Where an answered walk sends a guest, or null.')]
        public ?string $to,
        #[Description('The paths the walk took, in order; one per Redirect followed.')]
        public array $steps,
        #[Description('How many Redirects the walk followed. More than one is a chain: the server walks it and answers once.')]
        public int $hops,
        #[Description('Whether the walk fails a guest: a loop, too long, a dead end or a gone target.')]
        public bool $attention,
        #[Description('The page the walk lands on, or the page its last Redirect names when it stops short; null when no page is involved.')]
        public ?string $pageId,
    ) {}
}
