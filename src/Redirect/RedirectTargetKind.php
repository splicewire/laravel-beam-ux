<?php

namespace Splicewire\Beam\Ux\Redirect;

/** What a Redirect's target names (ADR-0236 d5); the `beam_ux_redirects.target_kind` vocabulary. */
enum RedirectTargetKind: string
{
    /** A page, held by identity once saved. */
    case Page = 'page';

    /** A Record, given as `collection:id`. */
    case Record = 'record';

    /** A path on this site. */
    case Path = 'path';

    /** An address on another site. */
    case Address = 'address';
}
