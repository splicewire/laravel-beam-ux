<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

#[TypeScript, Title('Release action')]
final class ReleaseCommandData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Description('What to do to the Release: `member` places an entry, `drop` removes one, `rebase` re-checks a member against the entry as it is now while keeping its selected version, `use-latest` moves a member to the entry's newest saved version, and `submit`, `approve`, `publish`, `schedule` and `rollback` move the Release itself.')]
        public string $action,
        #[Description('The entry the member actions (`member`, `drop`, `rebase`, `use-latest`) act on: its uuid. Required for those, ignored by the others.')]
        public ?string $entryId = null,
        #[Description('For `member`: the saved version of the entry to publish. Omitted, the member is a Take-down that unpublishes the entry.')]
        public ?string $ref = null,
        #[Description('For a Take-down `member` only: the published entry in the same realm to redirect to, by id or by URL.')]
        public ?string $redirectTarget = null,
        #[Description('For `schedule`: the instant the Release becomes due to publish. Required for `schedule`.')]
        public ?string $dueAt = null,
        #[Description('For `schedule`: the IANA timezone the schedule is kept in, e.g. `America/Chicago`. Required for `schedule`.')]
        public ?string $timezone = null,
    ) {}

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
