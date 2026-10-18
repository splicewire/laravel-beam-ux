<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Read source')]
class EntrySourceReadInputData extends BeamData
{
    /** @param array<int, array<string, mixed>> $body The Local Session body that was printed. */
    public function __construct(public string $source, public array $body) {}

    public static function rules(): array
    {
        return ['source' => ['present', 'string'], 'body' => ['present', 'array']];
    }
}
