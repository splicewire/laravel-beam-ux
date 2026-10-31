<?php

namespace Splicewire\Beam\Ux\Data;

use Splicewire\Beam\Data\BeamData;

#[\Spatie\TypeScriptTransformer\Attributes\TypeScript]
final class SchemaVersionPageImpactData extends BeamData
{
    /** @param list<string> $fields
     * @param  array<string, string>  $dependencies  Published parent declarations used by this page.
     */
    public function __construct(public string $id, public string $title, public string $publishedVersion, #[\Schemastud\DataSchemas\Attributes\ArrayItems('string')] public array $fields, #[\Schemastud\DataSchemas\Attributes\MapValues('string')] public array $dependencies = []) {}
}
