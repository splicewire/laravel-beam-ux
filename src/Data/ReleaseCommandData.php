<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;

#[TypeScript, Title('Release action')]
final class ReleaseCommandData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Description('What to do to the Release: `member` places an entry, `drop` removes one, `rebase` re-checks a member against the entry as it is now while keeping its selected version, `use-latest` moves a member to the entry\'s newest saved version, and `submit`, `approve`, `publish`, `schedule` and `rollback` move the Release itself; `links-on`, `links-off` and `rotate-links` manage its Preview Links (rotating ends every link).')]
        public string $action,
        #[Description('The entry the member actions (`member`, `drop`, `rebase`, `use-latest`) act on: its uuid. Required for those, ignored by the others.')]
        public ?string $entryId = null,
        #[Description('For `member`: the saved version of the entry to publish (entry kind), or the config section ROOT (a non-entry kind). For an entry, omitted means a Take-down that unpublishes the entry.')]
        public ?string $ref = null,
        #[Description('For `member`: the member KIND. `entry` (default) places an entry by `entryId`; a non-entry kind such as `config-override` places that kind of member by its `ref`, with no `entryId` (config-sections 08).')]
        public ?string $kind = null,
        #[Description('For a Take-down `member` only: the published entry in the same realm to redirect to, by id or by URL.')]
        public ?string $redirectTarget = null,
        #[Description('For `schedule`: the instant the Release becomes due to publish. Required for `schedule`.')]
        public ?string $dueAt = null,
        #[Description('For `schedule`: the IANA timezone the schedule is kept in, e.g. `America/Chicago`. Required for `schedule`.')]
        public ?string $timezone = null,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        $action = $context->payload['action'] ?? null;
        $kind = $context->payload['kind'] ?? BeamUxReleaseMember::KIND_ENTRY;
        // A NON-entry `member` (config-sections 08) identifies its subject by kind + ref (the section root) — no entryId —
        // and routes to putMemberByKind; an entry `member` is unchanged (entryId required, ref is the version).
        // config-sections 11: `drop` takes the same kind + ref, so a reviewer drops a config section as they drop an entry.
        $nonEntryMember = in_array($action, ['member', 'drop'], true) && $kind !== BeamUxReleaseMember::KIND_ENTRY;

        return [
            'action' => ['required', 'in:member,drop,rebase,use-latest,submit,approve,publish,schedule,rollback,links-on,links-off,rotate-links'],
            'kind' => ['nullable', 'string', 'in:'.BeamUxReleaseMember::KIND_ENTRY.','.BeamUxReleaseMember::KIND_CONFIG],
            'entryId' => [$nonEntryMember ? 'nullable' : 'required_if:action,member,drop,rebase,use-latest', 'nullable', 'uuid'],
            'ref' => [$nonEntryMember ? 'required' : 'nullable', 'string'],
            'redirectTarget' => ['nullable', 'string', 'max:255'],
            'dueAt' => ['required_if:action,schedule', 'nullable', 'string'],
            'timezone' => ['required_if:action,schedule', 'nullable', 'timezone'],
        ];
    }
}
