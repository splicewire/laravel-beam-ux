<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/**
 * The record-page binding a draft write accepts: {@see RecordPageData}'s input twin, as
 * {@see EntryPageSettingsInputData} is {@see EntryPageSettingsData}'s. A default makes a property optional only in a
 * request schema, so the one class nested on both axes rendered two shapes under one OpenAPI component name, and a host
 * documenting both the draft write and the entry body could not build its API reference.
 */
#[TypeScript, Title('Record page')]
final class RecordPageInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        public string $collection,
        public string $field,
        public ?string $titleField = null,
        public ?string $descriptionField = null,
        public ?string $socialImageField = null,
    ) {}

    public function toRecordPageData(): RecordPageData
    {
        return new RecordPageData(
            collection: $this->collection,
            field: $this->field,
            titleField: $this->titleField,
            descriptionField: $this->descriptionField,
            socialImageField: $this->socialImageField,
        );
    }
}
