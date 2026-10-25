<?php

namespace Splicewire\Beam\Ux\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;
use Splicewire\Beam\Data\BeamData;

/** Author-only read projection; nothing here updates the Local Session document or its saved head. */
#[TypeScript]
final class EntryCanvasBindingsOutputData extends BeamData
{
    public function __construct(
        #[TypeScriptType('array<string, RepeatRecordsData>')]
        public array $bindings,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public ?array $record,
        #[TypeScriptType('list<CanvasRecordChoiceData>')]
        #[DataCollectionOf(CanvasRecordChoiceData::class)]
        public array $choices,
        public bool $hasMore,
        public bool $recordPage,
        public ?string $selectedValue,
        /** Guest access intent, not a claim that this Working Head is published. Null means unknown. */
        public ?bool $pagePublic = null,
    ) {}
}
