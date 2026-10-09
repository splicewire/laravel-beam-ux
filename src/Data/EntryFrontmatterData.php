<?php

namespace Splicewire\Beam\Ux\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\DeclaresFrontmatterFields;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\Frontmatter;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\HydratesFromFrontmatter;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\RetainsUnknownFields;
use Splicewire\Beam\Mdx\Frontmatter\ParsedFrontmatter;

/**
 * beam-ux's OWN frontmatter vocabulary (frontmatter-declaration-seam ticket 05) — the first real
 * consumer of the {@see Frontmatter} seam, and the demonstration of why the shape is per-consumer.
 *
 * The grammar is shared; this is not. `realm`, `segment`, `navOrder`, `navGroup` and
 * `workflowMarking` are **beam-ux's facts** — they name this package's containment model and its
 * entry columns. `laravel-beam-mdx` must not know about them, which is exactly why its shipped
 * `FrontmatterData` carries only `title`/`layout`/`template` (what the *format* owns) and everything
 * else rides `unknown()`.
 *
 * ## The mapper pin
 *
 * Every multi-word field pins its package-owned camelCase wire name. This keeps the public shape
 * independent of a host's global data mapper. {@see fromFrontmatter()} reads the parser's raw authored
 * keys so the retired snake_case spellings do not become aliases through grammar canonicalization.
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
class EntryFrontmatterData extends Data implements DeclaresFrontmatterFields, Frontmatter, HydratesFromFrontmatter, RetainsUnknownFields, SchemaIdentity
{
    use RejectsUnknownInputKeys;

    /**
     * @param  array<string, string>  $leftovers  canonical keys this shape does not declare
     */
    public function __construct(
        public ?string $realm = null,
        public ?string $segment = null,
        #[MapInputName('navOrder')]
        public ?int $navOrder = null,
        #[MapInputName('navGroup')]
        public ?string $navGroup = null,
        public ?string $title = null,
        public ?string $layout = null,
        public ?string $template = null,
        #[MapInputName('workflowMarking')]
        public ?string $workflowMarking = null,
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
        return ['realm', 'segment', 'navOrder', 'navGroup', 'title', 'layout', 'template', 'workflowMarking'];
    }

    public static function fromFrontmatter(ParsedFrontmatter $parsed): static
    {
        $declared = array_flip(static::fieldNames());

        return static::from([
            ...array_intersect_key($parsed->raw, $declared),
            'leftovers' => array_diff_key($parsed->raw, $declared),
        ]);
    }

    /** @return array<string, string> */
    public function unknown(): array
    {
        return $this->leftovers;
    }
}
