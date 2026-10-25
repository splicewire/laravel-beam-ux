<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Data\BeamData;

/** A host's Page Fields for the pages under a recipes section (ADR-0233 d4). */
#[Title('Recipe fields')]
final class RecipeFields extends BeamData
{
    public function __construct(
        public ?int $servings = null,
        public ?string $cuisine = null,
        public ?bool $vegetarian = null,
    ) {}
}
