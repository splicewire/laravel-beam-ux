<?php

namespace Splicewire\Beam\Ux\Components;

/** Request-local catalogue reads only. Native mutations invalidate them; workers never share them. */
final class ComponentScope
{
    public array $lookups = [];

    public array $documents = [];

    public array $issues = [];

    public array $columns = [];

    public array $deliveries = [];

    public bool $privateCache = false;

    public function forget(): void
    {
        $this->lookups = $this->documents = $this->issues = $this->columns = $this->deliveries = [];
        $this->privateCache = false;
    }
}
