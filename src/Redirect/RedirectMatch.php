<?php

namespace Splicewire\Beam\Ux\Redirect;

/** How a Redirect's `path` matches a request (ADR-0236 d5); the `beam_ux_redirects.match` vocabulary. */
enum RedirectMatch: string
{
    /** This path only. */
    case Exact = 'exact';

    /** This path and everything under it, the rest carried to the target. */
    case Prefix = 'prefix';
}
