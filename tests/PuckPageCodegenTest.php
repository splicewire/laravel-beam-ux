<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codegen\PuckPageCodegen;

/**
 * The **Puck-Data → TSX codegen** (ADR-0164, the "NEXT" slice replacing the json mirror). A `page`
 * entry's edit-truth is its Puck `Data` ({root, content, zones}); on Publish that structural body is
 * CODEGEN'd to a clean, git-trackable **composed-JSX `.tsx`** — generated OUTPUT, never read back.
 *
 * Asserts: the emitted file carries the `@generated` provenance marker (the write-safety key — the
 * mirror refuses to clobber any file lacking it); imports the block vocabulary from the configured
 * blocks module; composes each `content` node as real JSX (`<Heading text="…" />`); serializes a
 * multiline/mdx prop as a template literal (not a mangled attribute); and recurses a Section's slot
 * (its `zones` children) as nested JSX children.
 */
class PuckPageCodegenTest extends TestCase
{
    private function codegen(): PuckPageCodegen
    {
        return new PuckPageCodegen('@/puck/blocks');
    }

    public function test_generates_a_marked_default_export_component_named_from_slug(): void
    {
        $tsx = $this->codegen()->generate(['root' => [], 'content' => [], 'zones' => []], 'library-lyrics');

        $this->assertStringContainsString(PuckPageCodegen::MARKER, $tsx);
        $this->assertTrue($this->codegen()->isGenerated($tsx), 'a codegen output must be recognizable as generated');
        $this->assertStringContainsString('export default function LibraryLyricsPage()', $tsx);
    }

    public function test_composes_content_nodes_as_jsx_and_imports_their_blocks(): void
    {
        $data = [
            'root' => [],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'h1', 'text' => 'Lyrics']],
                ['type' => 'ResourceList', 'props' => ['id' => 'r1', 'resource' => 'library-lyrics']],
            ],
            'zones' => [],
        ];

        $tsx = $this->codegen()->generate($data, 'library-lyrics');

        // A single import of exactly the block types used, from the configured module.
        $this->assertStringContainsString("import { Heading, ResourceList } from '@/puck/blocks';", $tsx);
        // Real composed JSX with quoted single-line string attrs; the structural `id` is NOT emitted.
        $this->assertStringContainsString('<Heading text="Lyrics" />', $tsx);
        $this->assertStringContainsString('<ResourceList resource="library-lyrics" />', $tsx);
        $this->assertStringNotContainsString('id="h1"', $tsx);
    }

    public function test_multiline_prop_serializes_as_a_template_literal(): void
    {
        $data = [
            'content' => [
                ['type' => 'Prose', 'props' => ['id' => 'p1', 'mdx' => "## Your words\n\nThe lyrics you've `written`."]],
            ],
        ];

        $tsx = $this->codegen()->generate($data, 'library-lyrics');

        // A multiline / backtick-bearing string must ride a template literal, with backticks escaped —
        // never a broken double-quoted attribute.
        $this->assertStringContainsString('mdx={`', $tsx);
        $this->assertStringContainsString('## Your words', $tsx);
        $this->assertStringContainsString('\\`written\\`', $tsx);
        $this->assertStringNotContainsString('mdx="## Your words', $tsx);
    }

    public function test_section_slot_recurses_zone_children_as_nested_jsx(): void
    {
        $data = [
            'content' => [
                ['type' => 'Section', 'props' => ['id' => 'sec1', 'heading' => 'A section']],
            ],
            'zones' => [
                'sec1-content' => [
                    ['type' => 'Heading', 'props' => ['id' => 'h2', 'text' => 'Nested']],
                ],
            ],
        ];

        $tsx = $this->codegen()->generate($data, 'section-template');

        // A node with zone children opens/closes and nests its children (not self-closing).
        $this->assertStringContainsString('<Section heading="A section">', $tsx);
        $this->assertStringContainsString('<Heading text="Nested" />', $tsx);
        $this->assertStringContainsString('</Section>', $tsx);
        // The nested block type is imported too.
        $this->assertStringContainsString('Section', $tsx);
    }

    public function test_section_inline_slot_prop_recurses_as_nested_jsx(): void
    {
        // Puck 0.20 native slots store nested content INLINE in the slot prop (an array of nodes), NOT in
        // top-level zones — this is the shape the seed command produces. The codegen must nest it, not drop it.
        $data = [
            'content' => [
                [
                    'type' => 'Section',
                    'props' => [
                        'id' => 'sec1',
                        'heading' => 'A section',
                        'content' => [
                            ['type' => 'Prose', 'props' => ['id' => 'p', 'mdx' => 'Nested prose.']],
                        ],
                    ],
                ],
            ],
            'zones' => [],
        ];

        $tsx = $this->codegen()->generate($data, 'section-template');

        $this->assertStringContainsString('<Section heading="A section">', $tsx);
        $this->assertStringContainsString('Nested prose.', $tsx);
        $this->assertStringContainsString('</Section>', $tsx);
        // The inline slot array is NOT leaked as an attribute.
        $this->assertStringNotContainsString('content=', $tsx);
        // Both block types are imported.
        $this->assertStringContainsString("import { Prose, Section } from '@/puck/blocks';", $tsx);
    }

    public function test_double_quote_in_string_prop_forces_template_literal(): void
    {
        $data = ['content' => [['type' => 'Heading', 'props' => ['text' => 'She said "hi"']]]];

        $tsx = $this->codegen()->generate($data, 'x');

        $this->assertStringContainsString('text={`She said "hi"`}', $tsx);
    }

    public function test_null_prop_is_dropped_not_emitted_as_empty_attr(): void
    {
        $data = ['content' => [['type' => 'Heading', 'props' => ['text' => 'Hi', 'subtitle' => null]]]];

        $tsx = $this->codegen()->generate($data, 'x');

        $this->assertStringContainsString('<Heading text="Hi" />', $tsx);
        $this->assertStringNotContainsString('subtitle', $tsx);
    }
}
