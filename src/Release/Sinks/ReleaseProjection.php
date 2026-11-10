<?php

namespace Splicewire\Beam\Ux\Release\Sinks;

/**
 * What one member kind published in a Release, handed to every {@see PublishSink} (ADR-0243). The items are the kind's
 * own shape: for `entry`, `published` holds `[BeamUxEntry, version, body]` with an optional `moved` slot (ADR-0233 d5)
 * and `withdrawn` holds each Take-down's `[BeamUxEntry, version, body]`. A sink reads the kinds it knows.
 *
 * @api
 */
final readonly class ReleaseProjection
{
    /**
     * @param  array<int|string, mixed>  $published
     * @param  list<mixed>  $withdrawn
     */
    public function __construct(
        public string $kind,
        public array $published,
        public array $withdrawn = [],
    ) {}
}
