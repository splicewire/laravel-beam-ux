<?php

namespace Splicewire\Beam\Ux\Placement;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **default file-placement strategy** (charter S2) — the direct successor of the pre-format
 * hardcoded `type/namespace/component.tsx`, now format-aware. It composes:
 *
 *   `{namespace dots→slashes}/{type}/{slug}.{format-extension}`
 *
 * e.g. `kit.hero` + `component` + `hero` + `tsx` → `kit/hero/component/hero.tsx`; the same entry as
 * `mdx` → `…/hero.mdx`. The **extension comes from the entry's `format`** (ADR-0164) via its codec —
 * NOT hardcoded — so an mdx entry files as `.mdx` with no change here. A null/empty `namespace` drops
 * the grouping segment (the entry files under `{type}/{slug}.{ext}`).
 *
 * `namespace` is the **disk-grouping coordinate only** (ADR-0165 two-trees): this derives the disk
 * *path*, never the URL.
 */
class DefaultPlacement implements FilePlacement
{
    public function pathFor(BeamUxEntry $entry): string
    {
        $extension = $entry->codec()->extension();
        $type = $entry->type?->value ?? (string) $entry->getAttribute('type');

        $segments = [];

        if (is_string($entry->namespace) && $entry->namespace !== '') {
            $segments[] = str_replace('.', '/', $entry->namespace);
        }

        $segments[] = $type;

        return implode('/', $segments).'/'.$entry->slug.'.'.$extension;
    }
}
