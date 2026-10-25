<?php

namespace Splicewire\Beam\Ux\Files;

use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ADR-0233 d3 — the FILE of an MDX page that holds blocks: its page settings printed as frontmatter, then its body as
 * the MDX Lens prints it. Frontmatter isn't part of the body; the version holds the settings, the mirror prints them
 * here, and import ({@see ThemeReconciliation}'s Take) reads them back.
 *
 * The header is canonical so a file the mirror wrote reads back to the bytes it came from: the page-settings names in
 * camelCase, in {@see KEYS} order, one flat `key: value` line each, with a null or default value left out; then the
 * keys nothing declares (the settings' `unclaimed`), sorted, under their snake_case names. `parentId` is never printed:
 * it is a foreign key, and the disk tree supplies it (`RegisterEntriesFromDisk`). The grammar is
 * {@see FrontmatterParser}'s flat scalars, so a value it cannot carry is refused when it is saved
 * ({@see assertPrintable()}), never printed lossily.
 */
final class MdxPageFile
{
    /** The page settings a header carries, in the order it prints them. */
    public const KEYS = ['title', 'segment', 'navOrder', 'navGroup', 'layout', 'template', 'description', 'socialImage', 'pageType', 'publishedDate'];

    /** The settings whose default is a value rather than null, left out of a header like a null. */
    private const DEFAULTS = ['pageType' => 'WebPage'];

    /** Does this entry's file take a page-settings header — an MDX page that holds blocks (ADR-0233 d1)? */
    public static function applies(BeamUxEntry $entry): bool
    {
        return $entry->getAttribute('type') === UxType::Page && $entry->format === UxFormat::Mdx
            && $entry->bodyKind() === BodyKind::Blocks;
    }

    /**
     * The served page settings of an entry: its columns, which Publish sets from the published version, and the
     * unclaimed keys that version holds (they have no column).
     */
    public static function settingsOf(BeamUxEntry $entry): EntryPageSettingsData
    {
        $settings = EntryPageSettingsData::fromEntry($entry);
        $pin = $entry->getAttribute('published_version');
        $version = $entry->particle_id === null || $pin === null ? null : app(VersionStore::class)->version($entry->versionable(), (string) $pin);
        $settings->unclaimed = $version?->snapshot['meta']['pageSettings']['unclaimed'] ?? [];

        return $settings;
    }

    public static function print(EntryPageSettingsData $settings, string $body): string
    {
        $lines = '';
        $unclaimed = $settings->unclaimed;
        ksort($unclaimed);
        foreach ([...self::fields($settings), ...$unclaimed] as $key => $value) {
            $lines .= $key.': '.$value."\n";
        }

        return "---\n".$lines."---\n\n".$body;
    }

    /**
     * The header's declared fields as the file carries them: printable strings, null and default values left out.
     *
     * @return array<string, string>
     */
    public static function fields(EntryPageSettingsData $settings): array
    {
        $fields = [];
        foreach (self::KEYS as $key) {
            $value = $settings->{$key};
            if ($value === null || $value === '' || $value === (self::DEFAULTS[$key] ?? null)) {
                continue;
            }
            $fields[$key] = (string) $value;
        }

        return $fields;
    }

    /**
     * Read a file back: its header's page-settings fields (named in camelCase, whichever spelling the file used), the
     * keys nothing declares, and the body after the header.
     *
     * @return array{fields: array<string, string>, unknown: array<string, string>, body: string}
     */
    public static function read(string $bytes): array
    {
        if (! str_starts_with($bytes, "---\n")) {
            throw new FileConflict('invalid', 'An MDX page file begins with its page settings as a frontmatter header (ADR-0233 d3).', 422);
        }
        $parsed = app(FrontmatterParser::class)->parse($bytes);
        $declared = [];
        foreach (self::KEYS as $key) {
            $declared[FrontmatterParser::canonicalize($key)] = $key;
        }
        $fields = [];
        $unknown = [];
        foreach ($parsed->fields as $canonical => $value) {
            if (isset($declared[$canonical])) {
                $fields[$declared[$canonical]] = $value;
            } else {
                $unknown[$canonical] = $value;
            }
        }

        return ['fields' => $fields, 'unknown' => $unknown, 'body' => ltrim($parsed->content, "\n")];
    }

    /**
     * The page settings a header names, validated as any page-settings save is. `parentId` is the entry's own: a
     * header never carries it.
     *
     * @param  array<string, string>  $fields  {@see read()}'s fields
     * @param  array<string, string>  $unclaimed  {@see read()}'s unknown keys, kept
     */
    public static function settingsFrom(array $fields, ?string $parentId, array $unclaimed = []): EntryPageSettingsData
    {
        if (isset($fields['navOrder']) && preg_match('/^-?[0-9]+$/D', $fields['navOrder']) === 1) {
            $fields['navOrder'] = (int) $fields['navOrder'];
        }

        return EntryPageSettingsData::validateAndCreate([...$fields, 'parentId' => $parentId, 'unclaimed' => $unclaimed]);
    }

    /** Refuse a page-settings value the flat frontmatter grammar would not read back as written. */
    public static function assertPrintable(EntryPageSettingsData $settings): void
    {
        $unclaimed = [];
        foreach ($settings->unclaimed as $key => $value) {
            $unclaimed["unclaimed.{$key}"] = $value;
        }
        foreach ([...self::fields($settings), ...$unclaimed] as $key => $value) {
            if (preg_match('/[\r\n]/', $value) === 1 || trim($value, " \t\"'") !== $value) {
                throw ValidationException::withMessages(["pageSettings.{$key}" => 'An MDX page prints its settings as frontmatter, which carries one line per value without surrounding spaces or quotes (ADR-0233 d3).']);
            }
        }
    }
}
