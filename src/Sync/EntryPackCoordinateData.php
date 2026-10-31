<?php

namespace Splicewire\Beam\Ux\Sync;

use Splicewire\Sync\Transit\SyncData;

final class EntryPackCoordinateData extends SyncData
{
    public function __construct(public ?string $namespace, public string $slug, public ?string $realm, public string $type) {}
}
