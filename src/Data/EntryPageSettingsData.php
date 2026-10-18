<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR-0232 d1/d2 and ADR-0234 d6: the guest-visible fields in the particle's versioned meta. */
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
        ];
    }

    public static function fromEntry(BeamUxEntry $entry): self
    {
        return new self(
            title: $entry->title ?? '', parentId: $entry->parent_id, segment: $entry->segment,
            navOrder: $entry->nav_order, navGroup: $entry->nav_group, layout: $entry->layout,
            template: $entry->template, description: $entry->seo_description,
            socialImage: $entry->seo_social_image, pageType: $entry->seo_page_type ?? 'WebPage',
            publishedDate: $entry->seo_published_date,
        );
    }
}
