<?php

namespace Splicewire\Beam\Ux\Release\Kinds;

/**
 * What a Release asks of an actor for one member (ADR-0226 d1, ADR-0243).
 *
 * - `Edit`: placing, dropping or reviewing the member of a Release built member by member.
 * - `Publish`: approving, scheduling, publishing or recording that Release (edit, then publish).
 * - `Direct`: every check on a direct Release of one, which decides on the publish ladder alone.
 */
enum ReleaseAccess
{
    case Edit;
    case Publish;
    case Direct;
}
