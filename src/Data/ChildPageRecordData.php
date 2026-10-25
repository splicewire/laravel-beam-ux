<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Data\BeamData;

/**
 * The owning entry package declares the child-page binding vocabulary. `fields` are the child's values for the Page
 * Fields its parent declares (ADR-0233 d4), the Collection's Record fields beside its own.
 */
#[Title('Child page')]
final class ChildPageRecordData extends BeamData
{
    /** @param  array<string, string|int|float|bool>  $fields */
    public function __construct(public string $id, public string $slug, public ?string $title, public string $url, public array $fields = []) {}
}
