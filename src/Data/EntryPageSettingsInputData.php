<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** The partial page-settings contract accepted by the draft write operation. */
#[TypeScript]
#[Title('Page settings input')]
final class EntryPageSettingsInputData extends BeamData
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
        /** @var array<string, string> */
        public array $unclaimed = [],
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return EntryPageSettingsData::rules();
    }

    public function toPageSettingsData(): EntryPageSettingsData
    {
        return new EntryPageSettingsData(
            title: $this->title,
            parentId: $this->parentId,
            segment: $this->segment,
            navOrder: $this->navOrder,
            navGroup: $this->navGroup,
            layout: $this->layout,
            template: $this->template,
            description: $this->description,
            socialImage: $this->socialImage,
            pageType: $this->pageType,
            publishedDate: $this->publishedDate,
            unclaimed: $this->unclaimed,
        );
    }
}
