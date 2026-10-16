<?php

namespace Splicewire\Beam\Ux\Manifest;

/**
 * ADR-0228 d6 (EP-07a, part a) — `laravel-beam-ux` ships a base profile of the intrinsic HTML elements,
 * with open attributes. A node's name is its JSX tag. This is the lower half of every realm's Manifest
 * Profile: the per-realm resolver (EP-07a b) composes this base with the host's placeable `#[NodeType]`
 * components, and a save checks each node against the composed vocabulary (EP-07a c).
 *
 * Intrinsic HTML elements carry no Block class and accept any attribute, so each entry has an OPEN
 * `attrsSchema` (`additionalProperties: true`) and open containment — a container admits the single
 * `html` category (HTML nests freely within itself); a void element is a childless, text-less leaf.
 * The entry shape mirrors {@see \Schemastud\Blockdoc\Schema\ManifestBuilder::node()}'s v1 output so a
 * resolver can concatenate the two node lists and the JS `assemblePMSchema([base, profile])` reads one
 * vocabulary. Kept as plain arrays because Blockdoc has no PHP `NodeManifestEntry` class — the entry is
 * an array on the PHP side (the grammar is frozen as the `@schemastud/blockdoc` v1 TS interface).
 */
final class HtmlManifestProfile
{
    /** The profile name the intrinsic-HTML base ships under. */
    public const PROFILE = 'html';

    /** The single category every intrinsic HTML element belongs to and nests within. */
    public const CATEGORY = 'html';

    /**
     * Void (self-closing) intrinsic elements: a leaf that bears no text and admits no children.
     *
     * @var list<string>
     */
    private const VOID = ['img', 'hr', 'br'];

    /**
     * The intrinsic HTML elements the base profile ships. The node name is the JSX tag (ADR-0228 d6).
     *
     * @var list<string>
     */
    private const ELEMENTS = [
        // flow / structural
        'section', 'div', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'figure', 'figcaption',
        // text / prose
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'a', 'strong', 'em', 'small', 'blockquote', 'pre', 'code',
        // lists
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        // interactive / media
        'button', 'label', 'img',
        // tables
        'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        // void
        'hr', 'br',
    ];

    /**
     * The HTML base profile as a v1 Blockdoc manifest: `{ profile, version, doc, nodes, marks }`.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'profile' => self::PROFILE,
            'version' => 1,
            'doc' => ['admitsChildCategories' => [self::CATEGORY]],
            'nodes' => array_map(static fn (string $tag): array => self::node($tag), self::ELEMENTS),
            'marks' => [],
        ];
    }

    /**
     * One intrinsic element as a v1 manifest node entry (the `ManifestBuilder::node()` key order).
     *
     * @return array<string, mixed>
     */
    private static function node(string $tag): array
    {
        $isVoid = in_array($tag, self::VOID, true);

        return [
            'name' => $tag,
            'group' => 'block',
            'category' => self::CATEGORY,
            // A container admits the single HTML category; a void element is a childless leaf.
            'admitsChildCategories' => $isVoid ? [] : [self::CATEGORY],
            'admitsText' => ! $isVoid,
            'contentExpression' => null,
            // Open attributes (d6): any attribute is allowed, with the stable d3 `id` typed.
            'attrsSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => ['string', 'null']]],
                'additionalProperties' => true,
            ],
        ];
    }
}
