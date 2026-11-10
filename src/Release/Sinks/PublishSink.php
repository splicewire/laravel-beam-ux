<?php

namespace Splicewire\Beam\Ux\Release\Sinks;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

/**
 * A projection of a published Release outside its database (ADR-0243 amending ADR-0226): a disk mirror, a Git record.
 *
 * The database pin IS the publication, and is not a sink. A sink works in two phases, and the registry owns the
 * boundary of both:
 *
 *  - **Freeze**, in {@see project()}: called inside the publish transaction, once a kind has published, in its own
 *    savepoint. A sink records there what it will project (a receipt row). What it throws is contained and rolls back
 *    its savepoint alone.
 *  - **Project**, the closure {@see project()} returns: run by the registry once the publication commits, never from a
 *    Release that rolled back. What it throws is contained there too, and every later sink's projection still runs.
 *
 * A sink never registers its own after-commit work: that would run outside the registry's containment, so a failure
 * there would reach the publisher after the pin committed and skip the sinks after it.
 *
 * @api
 */
interface PublishSink
{
    /** The sink's key in {@see PublishSinkRegistry}. */
    public function name(): string;

    /**
     * Freeze what this sink will project of `$projection`, and return the projection to run after commit, or null when
     * there is nothing to project (a kind it does not know).
     */
    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): ?\Closure;
}
