<?php

namespace Splicewire\Beam\Ux\Bindings;

/** Explicit profile offer; the resource owns its fields and authorization, never this declaration. */
final readonly class CollectionSource
{
    public function __construct(
        public string $name,
        public ?string $resource,
        public array $address = [],
        public ?string $parentEntryId = null,
    ) {
        if (($resource === null) === ($parentEntryId === null)) {
            throw new \InvalidArgumentException('A Collection source declares either a resource or a child-page parent.');
        }
    }

    public static function childPages(string $name, string $parentEntryId): self
    {
        return new self($name, null, parentEntryId: $parentEntryId);
    }
}
