<?php

declare(strict_types=1);

namespace Splicewire\Beam\Ux\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Data\Pages\PageEntryData;

#[TypeScript]
final class EntryPageData extends Data
{
    public function __construct(
        public ?PageEntryData $entry,
    ) {}
}
