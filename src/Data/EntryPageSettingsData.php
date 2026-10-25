<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0232 d1/d2 and ADR-0234 d6: the guest-visible fields in the particle's versioned meta.
 *
 * `childFields` (ADR-0233 d4) names the Page Fields this page declares for its child pages, from the host's
 * `beam.ux.page_fields`; `fields` holds this page's own values for the Page Fields its parent declares, checked on save
 * ({@see \Splicewire\Beam\Ux\Containment\PageFields}). `unclaimed` (d3) holds the frontmatter keys nothing declares,
 * kept as written under their canonical snake_case names, so a file's keys survive import and the mirror. `fields` and
 * `unclaimed` have no column; the version holds them.
 */
#[TypeScript]
#[Title('Page settings')]
final class EntryPageSettingsData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        public string $title,
        public ?string $parentId = null,
        public ?string $segment = null,
        public ?int $navOrder = null,
        public ?string $navGroup = null,
        public ?string $layout = null,
        public ?string $template = null,
        public ?string $description = null,
        public ?string $socialImage = null,
        public string $pageType = 'WebPage',
        public ?string $publishedDate = null,
        public ?string $childFields = null,
        /** @var array<string, string|int|float|bool> */
        public array $fields = [],
        /** @var array<string, string> */
        public array $unclaimed = [],
    ) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'parentId' => ['nullable', 'uuid'],
            'segment' => ['nullable', 'string', 'max:255'],
            'navOrder' => ['nullable', 'integer'],
            'navGroup' => ['nullable', 'string', 'max:255'],
            'layout' => ['nullable', 'string', 'max:255'],
            'template' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'socialImage' => ['nullable', 'string', 'max:2048'],
            'pageType' => ['in:WebPage,Article,CollectionPage,ProfilePage'],
            'publishedDate' => ['nullable', 'date_format:Y-m-d'],
            'childFields' => ['nullable', 'string', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && \Splicewire\Beam\Ux\Containment\PageFields::classNamed((string) $value) === null) {
                    $fail('The host registers no Page Fields by this name (beam.ux.page_fields).');
                }
            }],
            'fields' => ['array'],
            'unclaimed' => ['array', static function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (is_array($value) ? array_keys($value) : [] as $key) {
                    if (! is_string($key) || preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/D', $key) !== 1) {
                        $fail('An unclaimed frontmatter key is a snake_case name.');
                    } elseif (in_array($key, self::reservedFrontmatterKeys(), true)) {
                        $fail("The unclaimed key [{$key}] is declared, so it cannot be kept as unclaimed.");
                    }
                }
            }],
            'unclaimed.*' => ['string', 'max:2000'],
        ];
    }

    /**
     * The frontmatter keys a page declares, in their canonical snake_case spelling: every page setting, and the keys
     * registration from disk reads onto the row (ADR-0212's rights, the realm, the workflow marking). An unclaimed
     * key may name none of them.
     *
     * @return list<string>
     */
    public static function reservedFrontmatterKeys(): array
    {
        $keys = [];
        foreach ((new \ReflectionClass(self::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $keys[] = \Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser::canonicalize($parameter->getName());
        }

        return array_values(array_unique([
            ...$keys,
            ...EntryFrontmatterData::fieldNames(),
            ...array_map(fn (\Splicewire\Beam\Ux\Access\Right $right) => $right->value, \Splicewire\Beam\Ux\Access\Right::cases()),
        ]));
    }

    public static function fromEntry(BeamUxEntry $entry): self
    {
        return new self(
            title: $entry->title ?? '', parentId: $entry->parent_id, segment: $entry->segment,
            navOrder: $entry->nav_order, navGroup: $entry->nav_group, layout: $entry->layout,
            template: $entry->template, description: $entry->seo_description,
            socialImage: $entry->seo_social_image, pageType: $entry->seo_page_type ?? 'WebPage',
            publishedDate: $entry->seo_published_date, childFields: $entry->getAttribute('child_fields'),
        );
    }
}
