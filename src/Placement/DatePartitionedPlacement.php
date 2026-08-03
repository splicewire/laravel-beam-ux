<?php

namespace Splicewire\Beam\Ux\Placement;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **date-partitioned file-placement strategy** (charter S2, ADR-0165 §6) — files content by DATE
 * under a fixed root instead of by module/type:
 *
 *   `{root}/{YYYY}/{MM}/{slug}.{format-extension}`   e.g. `articles/2026/08/my-post.mdx`
 *
 * The canonical use is a blog/articles surface where the disk grouping is chronological even though the
 * entry lives under `/blog/testing/…` in the containment tree — the concrete example ADR-0165 gives of
 * the **two trees** diverging: disk by date, URL by containment. The date is the entry's `created_at`
 * (falling back to now for an unsaved entry); the extension still comes from `format` (ADR-0164).
 */
class DatePartitionedPlacement implements FilePlacement
{
    public function __construct(protected string $root = 'articles') {}

    public function pathFor(BeamUxEntry $entry): string
    {
        $extension = $entry->codec()->extension();
        $date = $entry->created_at ?? now();

        return sprintf(
            '%s/%s/%s/%s.%s',
            trim($this->root, '/'),
            $date->format('Y'),
            $date->format('m'),
            $entry->slug,
            $extension,
        );
    }
}
