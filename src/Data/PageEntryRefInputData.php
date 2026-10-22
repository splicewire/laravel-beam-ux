<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript, Title('Resolve page entry')]
final class PageEntryRefInputData extends BeamData
{
    public function __construct(
        #[Description('The kit page\'s slug to locate an entry for. Resolving never creates the entry; make-editable does.')]
        public string $slug,
    ) {}

    public static function rules(): array
    {
        return ['slug' => ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/']];
    }
}
