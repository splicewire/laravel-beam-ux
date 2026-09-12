<?php

namespace Splicewire\Beam\Ux\Data;

use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\ResourceRef;
use Schemastud\Frame\Attributes\Widget;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The validated create/edit input for the `BeamUxEntry` Frame resource (theme-entries-and-authoring
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
 * **Auto-derived**: `namespace` is set to `''` on creation by `BeamUxEntryData::prepare()`
 * and omitted from this write map so updates retain their existing namespace (disk-only build-grouping — irrelevant to an
 * admin-created entry, and this fixed value never collides with the reserved `realms`/`theme`
 * namespaces `BeamUxEntry::rootFor()` and `Splicewire\Beam\Ux\Theme\ThemeResolver` use for their
 * own canonical rows). `schema_ref`/`format`/
 * `facade_ref`/`driver_ref`/`placement_ref` are left unset — each already resolves lazily at
 * read/write time from its own resolver (`PlacementResolver`, `StorageDriverResolver`, schema
 * inference), matching `ScaffoldCommand`'s own minimal-entry precedent; there is no static
 * `type → refs` map anywhere in this package to reproduce here.
 */
#[TypeScript]
#[Title('Entry')]
class BeamUxEntryInputData extends BeamData implements MapsToModelAttributes
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
        // Containment/nav fields (theme-entries-and-authoring provenance sweep, ux-demo-convergence
        // 2026-09-12): `segment` and `nav_order` were never actually deferred by an owner ruling — the
        // console form simply never carried them. `NavProjector::project()` already reads both LIVE off
        // the entry row (`nav_order` for sibling order, `segment` for the URL/whether the node is a nav
        // destination at all), so exposing them here is wiring an existing read to an existing write
        // seam, not new mechanism. `segment` null/'' is the documented pass-through state (no URL, no
        // nav link, children still splice through) — never rejected, only validated when present.
        #[Title('Segment')]
        public ?string $segment = null,
        #[Title('Nav order')]
        public ?int $nav_order = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        $current = self::currentEntry();
        $slug = Rule::unique('beam_ux_entries')->where('namespace', $current !== null ? $current->namespace : '')->withoutTrashed();
        if ($current !== null) {
            $slug->ignore($current);
        }

        return [
            'type' => ['required', 'string', Rule::in(self::CREATABLE_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            // New entries use namespace=''; edits preserve their existing namespace and exclude only
            // the persisted route target from uniqueness — the real
            // [namespace, slug] composite unique index (create_beam_ux_entries_table).
            'slug' => ['required', 'string', 'max:255', $slug],
            'realm' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Gate::allows("ux.{$value}.author")) {
                    $fail('You are not entitled to author entries in this realm.');
                }
            }],
            'parent_id' => ['nullable', 'string', 'exists:beam_ux_entries,id'],
            // Mirrors the real DB constraint (`create_beam_ux_entries_table.php.stub`:
            // `unique index … on beam_ux_entries (parent_id, segment) where deleted_at is null`) —
            // ONE public URL per (parent, segment). Scoped to `parent_id`, not realm: the database
            // index isn't realm-scoped either (an entry belongs to one row's `parent_id` regardless of
            // how many realms its `realms` fallback stack lists it in), so a laxer app-level rule would
            // let two entries collide at the same parent+segment in different realms and then fail at
            // the DB on save with a raw constraint violation instead of a 422. `nullable` short-circuits
            // this for null/omitted segments — multiple pass-through siblings sharing a null segment is
            // the documented, permitted shape (ContainmentTest::test_unplaced_page_entries_without_a_segment_are_excluded_from_nav).
            'segment' => ['nullable', 'string', 'max:255', self::segmentUniqueRule($context)],
            'nav_order' => ['nullable', 'integer'],
        ];
    }

    /**
     * Scoped like the `slug` rule above: same parent, excluding the persisted update target. The
     * submitted `parent_id` comes from the validation payload itself (`ValidationContext::$fullPayload`)
     * rather than `request('parent_id')` — this DTO's own tests call `validateAndCreate()` with a bare
     * array, never through an HTTP request, and the two would silently diverge on any such caller.
     */
    private static function segmentUniqueRule(ValidationContext $context): Unique
    {
        $current = self::currentEntry();
        $payload = is_array($context->fullPayload) ? $context->fullPayload : [];
        $parentId = $current !== null ? $current->parent_id : ($payload['parent_id'] ?? null);

        $rule = Rule::unique('beam_ux_entries')
            ->where('parent_id', $parentId)
            ->whereNotNull('segment')
            ->withoutTrashed();

        if ($current !== null) {
            $rule->ignore($current);
        }

        return $rule;
    }

    /** Resolve only the persisted update target, never an ID supplied in the body. */
    private static function currentEntry(): ?BeamUxEntry
    {
        $request = request();
        if (! in_array($request->method(), ['PUT', 'PATCH'], true)) {
            return null;
        }

        $id = $request->route('id');

        return $id instanceof BeamUxEntry ? $id : (is_string($id) ? BeamUxEntry::query()->find($id) : null);
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
            'segment' => $this->segment,
            'nav_order' => $this->nav_order,
        ];
    }
}
