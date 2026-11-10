<?php

namespace Splicewire\Beam\Ux\Release\Sinks;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxRelease;

/**
 * A projection of a published Release outside its database (ADR-0243 amending ADR-0226): a disk mirror, a Git record.
 *
 * The database pin IS the publication, and is not a sink. A sink is called inside the publish transaction, once a kind
 * has published, to freeze what it will project; the projecting itself happens after commit, so nothing reaches a disk
 * or a repository from a Release that rolled back. A sink may fail, and its failure never fails the publication: the
 * registry runs each in its own savepoint and contains what it throws, and a sink records its own failure where its
 * readers look (the recording receipt, for Git).
 *
 * @api
 */
interface PublishSink
{
    /** The sink's key in {@see PublishSinkRegistry}. */
    public function name(): string;

    /** Freeze what this sink will project of `$projection`, and register its after-commit work. Ignore kinds it does not know. */
    public function project(BeamUxRelease $release, ReleaseProjection $projection, Authenticatable $actor): void;
}
