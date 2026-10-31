<?php

namespace Splicewire\Beam\Ux\Sync;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Host composition supplies schema/Record dependencies without an authoring-layer UP dependency. */
interface EntryPackDependencies
{
    /** @return array<class-string, list<string>> */
    public function dependencies(BeamUxEntry $entry): array;

    public function export(BeamUxEntry $entry, array $payload): array;

    /** Rewrite identities before Reasserter computes the target's asserted body hash. */
    public function import(array $payload, array $config): array;
}
