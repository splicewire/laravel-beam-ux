<?php

namespace Splicewire\Beam\Ux\Bindings;

/** Routing declaration metadata, scoped to one request or queue job. Never caches Record reads or authorization. */
final class RecordPageScope
{
    public array $columns = [];

    public array $pages = [];

    public array $matches = [];

    public array $offers = [];

    public array $realmsColumns = [];

    public function forgetPages(): void
    {
        $this->pages = $this->matches = $this->offers = [];
    }
}
