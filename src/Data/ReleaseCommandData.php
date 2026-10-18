<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript, Title('Release action')]
final class ReleaseCommandData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(public string $action, public ?string $entryId = null, public ?string $ref = null,
        public ?string $redirectTarget = null, public ?string $dueAt = null, public ?string $timezone = null) {}

    public static function rules(): array
    {
        return [
            'action' => ['required', 'in:member,drop,rebase,use-latest,submit,approve,publish,schedule,rollback'],
            'entryId' => ['required_if:action,member,drop,rebase,use-latest', 'nullable', 'uuid'],
            'ref' => ['nullable', 'string'], 'redirectTarget' => ['nullable', 'string', 'max:255'],
            'dueAt' => ['required_if:action,schedule', 'nullable', 'string'],
            'timezone' => ['required_if:action,schedule', 'nullable', 'timezone'],
        ];
    }
}
