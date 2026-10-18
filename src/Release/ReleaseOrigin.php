<?php

namespace Splicewire\Beam\Ux\Release;

/**
 * A Release's provenance (`beam_ux_releases.origin`; owner ruling 2026-10-18). Only ReleaseService sets it: create()
 * opens a Built Release, and only requestOne() opens a Direct one, so a caller cannot claim the direct path.
 */
enum ReleaseOrigin: string
{
    /** A Release of one made by the editor's Publish or the Workflow tab's Take-down: decided on the publish ability. */
    case Direct = 'direct';

    /** A Release assembled member by member: separate edit and publish checks. */
    case Built = 'built';
}
