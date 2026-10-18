<?php

namespace Splicewire\Beam\Ux\Bindings;

/** Explicit profile offer; the resource owns its fields and authorization, never this declaration. */
final readonly class CollectionSource
{
    public function __construct(
        public string $name,
        public string $resource,
        public array $address = [],
    ) {}
}
