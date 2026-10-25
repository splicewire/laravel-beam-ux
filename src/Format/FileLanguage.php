<?php

namespace Splicewire\Beam\Ux\Format;

use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0233 d5 — a page's **File Language**: the language its file is written in, never what it looks like. It is
 * chosen at creation, defaulting to the parent's and then the host's (`beam.ux.file_language`); page settings show and
 * change it, the version holds it, and Publish moves the mirror file to it. `tsx` always; `mdx` only on a host whose MDX
 * pages hold blocks (d1). A Hosted site's pages have no File Language (d6): a tenant-resident page offers none.
 */
final class FileLanguage
{
    /** @return list<string> the File Languages this page may take; none for a tenant-resident page */
    public static function choices(BeamUxEntry $entry): array
    {
        if (! app(EntryResidency::class)->isCentralResident($entry)) {
            return [];
        }

        return config('beam.ux.mdx.holds_blocks') ? [UxFormat::Tsx->value, UxFormat::Mdx->value] : [UxFormat::Tsx->value];
    }

    /** A new page's File Language: its parent's when that is one it may take, else the host's, else `tsx`. */
    public static function defaultFor(BeamUxEntry $entry, ?string $parentId): string
    {
        $choices = self::choices($entry);
        $parent = $parentId === null ? null : $entry->newQuery()->whereKey($parentId)->first()?->format;
        foreach ([$parent instanceof UxFormat ? $parent->value : $parent, config('beam.ux.file_language')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $choices, true)) {
                return $candidate;
            }
        }

        return UxFormat::Tsx->value;
    }

    /** May this page take `$language`? Its current language always may (a host turning the gate off strands none). */
    public static function allows(BeamUxEntry $entry, string $language): bool
    {
        $current = $entry->format instanceof UxFormat ? $entry->format->value : $entry->getAttribute('format');

        return $language === $current || in_array($language, self::choices($entry), true);
    }
}
