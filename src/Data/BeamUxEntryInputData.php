<?php

namespace Splicewire\Beam\Ux\Data;

use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\ResourceRef;
use Schemastud\Frame\Attributes\Widget;
use Spatie\LaravelData\Data;

/**
 * The validated create-input for the `BeamUxEntry` Frame resource (theme-entries-and-authoring
 * ticket 05) — dissolved onto `ParticleFrameResourceHandler`'s `$resource->input::validateAndCreate()`
 * seam, the same one `ParticleController::store()` already runs (a standard 422 on a rules failure,
 * no bespoke controller).
 *
 * **User-supplied**: `type` (page/component/theme only — `layout`/`template` are rejected, zero
 * active consumers, no composition mechanism exists yet), `title`, `slug` (client auto-slugifies from
 * `title`, human-editable before submit — this DTO just validates the final value), `realm`
 * (entitlement-gated against `Gate::allows("ux.{$realm}.author")`, never trusted as free text —
 * see {@see rules()}), `parent_id` (an existing entry's id, the placement picker).
 *
 * **Auto-derived**: `namespace` always `''` (disk-only build-grouping — irrelevant to an
 * admin-created entry, and this fixed value never collides with the reserved `realms`/`theme`
 * namespaces `BeamUxEntry::rootFor()` and `Splicewire\Beam\Ux\Theme\ThemeResolver` use for their
 * own canonical rows). `schema_ref`/`format`/
 * `facade_ref`/`driver_ref`/`placement_ref` are left unset — each already resolves lazily at
 * read/write time from its own resolver (`PlacementResolver`, `StorageDriverResolver`, schema
 * inference), matching `ScaffoldCommand`'s own minimal-entry precedent; there is no static
 * `type → refs` map anywhere in this package to reproduce here.
 */
#[Title('New Entry')]
class BeamUxEntryInputData extends Data
{
    /** @var list<string> */
    public const CREATABLE_TYPES = ['page', 'component', 'theme'];

    public function __construct(
        #[Title('Type')]
        public string $type,
        #[Title('Title')]
        public string $title,
        #[Title('Slug')]
        public string $slug,
        #[Title('Realm'), Widget('combobox', options: ['suggestions' => ['site', 'operator', 'tenant', 'user']])]
        public string $realm,
        #[Title('Parent'), ResourceRef('beam-ux-entry', value: 'id', label: 'title')]
        public ?string $parent_id = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::CREATABLE_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            // Scoped to the fixed namespace='' every admin-created entry lands under — the real
            // [namespace, slug] composite unique index (create_beam_ux_entries_table).
            'slug' => ['required', 'string', 'max:255', Rule::unique('beam_ux_entries')->where('namespace', '')],
            'realm' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Gate::allows("ux.{$value}.author")) {
                    $fail('You are not entitled to author entries in this realm.');
                }
            }],
            'parent_id' => ['nullable', 'string', 'exists:beam_ux_entries,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function toModelAttributes(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'slug' => $this->slug,
            'realm' => $this->realm,
            'parent_id' => $this->parent_id,
            'namespace' => '',
        ];
    }
}
