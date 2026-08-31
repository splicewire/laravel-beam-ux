<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\DeclaresFrontmatterFields;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\Frontmatter;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\HydratesFromFrontmatter;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\RetainsUnknownFields;
use Splicewire\Beam\Mdx\Frontmatter\ParsedFrontmatter;

/**
 * beam-ux's OWN frontmatter vocabulary (frontmatter-declaration-seam ticket 05) — the first real
 * consumer of the {@see Frontmatter} seam, and the demonstration of why the shape is per-consumer.
 *
 * The grammar is shared; this is not. `realm`, `segment`, `nav_order`, `nav_group` and
 * `workflow_marking` are **beam-ux's facts** — they name this package's containment model and its
 * entry columns. `laravel-beam-mdx` must not know about them, which is exactly why its shipped
 * `FrontmatterData` carries only `title`/`layout`/`template` (what the *format* owns) and everything
 * else rides `unknown()`.
 *
 * ## The mapper pin
 *
 * `#[MapInputName(SnakeCaseMapper::class)]` for the same reason `FrontmatterData` carries it: unpinned,
 * this class inherits `config('data.name_mapping_strategy.input')`, which the estate's hosts do not
 * agree on (`CamelCaseMapper` at three, `SnakeCaseMapper` at two, absent at 16 of 21 Herd roots). The
 * pin is belt-and-braces over {@see \Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser}, which already
 * canonicalizes to snake at the grammar; it defends the path where a caller hands `::from()` a raw
 * array that never went through the parser.
 *
 * ## What is deliberately NOT modelled
 *
 * `parent_id` — the one containment field frontmatter cannot honestly carry, because it is a foreign
 * key and a `---` block can only name a parent by some other coordinate. The disk TREE supplies it.
 * That reasoning is `RegisterEntriesFromDisk`'s and is not restated here.
 *
 * The ADR-0212 rights and the ADR-0213 chrome columns stay column-GUARDED at the reader rather than
 * required here: whether a host has migrated them is a fact about the **host**, so it is an advisory
 * skip, never a validation failure on the declaration.
 */
#[MapInputName(SnakeCaseMapper::class)]
class EntryFrontmatterData extends Data implements DeclaresFrontmatterFields, Frontmatter, HydratesFromFrontmatter, RetainsUnknownFields, SchemaIdentity
{
    /**
     * @param  array<string, string>  $leftovers  canonical keys this shape does not declare
     */
    public function __construct(
        public ?string $realm = null,
        public ?string $segment = null,
        public ?int $nav_order = null,
        public ?string $nav_group = null,
        public ?string $title = null,
        public ?string $layout = null,
        public ?string $template = null,
        public ?string $workflow_marking = null,
        public array $leftovers = [],
    ) {}

    public static function schemaName(): string
    {
        return 'beam-ux/entry-frontmatter';
    }

    /** v1. A later reshape bumps this and freezes v1; it never edits this class in place. */
    public static function schemaVersion(): int
    {
        return 1;
    }

    /** @return list<string> */
    public static function fieldNames(): array
    {
        return ['realm', 'segment', 'nav_order', 'nav_group', 'title', 'layout', 'template', 'workflow_marking'];
    }

    public static function fromFrontmatter(ParsedFrontmatter $parsed): static
    {
        $declared = array_flip(static::fieldNames());

        return static::from([
            ...array_intersect_key($parsed->fields, $declared),
            'leftovers' => array_diff_key($parsed->fields, $declared),
        ]);
    }

    /** @return array<string, string> */
    public function unknown(): array
    {
        return $this->leftovers;
    }
}
