<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codec\JsonDocPrinter;

/**
 * Mirrors `@splicewire/beam-ux/blockdoc/json.test.ts`'s "jsonToTsx printer (Babel-free)" cases — this
 * is a behavioral PORT of that printer, so the same scenarios prove parity, not just PHP-side coverage.
 */
class JsonDocPrinterTest extends TestCase
{
    public function test_prints_scalar_prop_kinds_correctly(): void
    {
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'X', 'isComponent' => true, 'dynamic' => false, 'children' => [],
                'props' => [
                    ['name' => 'title', 'kind' => 'string', 'value' => 'hi'],
                    ['name' => 'count', 'kind' => 'number', 'value' => 3],
                    ['name' => 'live', 'kind' => 'boolean', 'value' => true],
                    ['name' => 'open', 'kind' => 'boolean-shorthand', 'value' => true],
                ],
            ],
        ]);

        $this->assertStringContainsString('title="hi"', $out);
        $this->assertStringContainsString('count={3}', $out);
        $this->assertStringContainsString('live={true}', $out);
        $this->assertMatchesRegularExpression('/ open\b/', $out);
    }

    public function test_escapes_jsx_special_characters_in_text_leaves(): void
    {
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [['kind' => 'text', 'value' => 'a < b > c']],
            ],
        ]);

        $this->assertStringContainsString("{'<'}", $out);
        $this->assertStringContainsString("{'>'}", $out);
    }

    public function test_a_text_only_leaf_prints_inline(): void
    {
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [['kind' => 'text', 'value' => 'Title']],
            ],
        ]);

        $this->assertSame('<h2>Title</h2>;', $out);
    }

    public function test_a_childless_element_prints_self_closing(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'block', 'name' => 'br', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ]);

        $this->assertSame('<br />;', $out);
    }

    public function test_a_fragment_root_has_no_tag_name(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'block', 'name' => null, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ]);

        $this->assertSame('<></>;', $out);
    }

    public function test_nested_block_children_print_indented_on_their_own_lines(): void
    {
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [
                    ['kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                        'children' => [['kind' => 'text', 'value' => 'Heading']]],
                    ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                        'children' => [['kind' => 'text', 'value' => 'Body']]],
                ],
            ],
        ]);

        $this->assertSame("<section>\n  <h1>Heading</h1>\n  <p>Body</p>\n</section>;", $out);
    }

    public function test_an_opaque_node_re_emits_its_source_verbatim(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'opaque', 'reason' => 'map', 'source' => '{items.map(i => <li key={i}>{i}</li>)}'],
        ]);

        $this->assertSame('{items.map(i => <li key={i}>{i}</li>)};', $out);
    }

    public function test_a_string_prop_does_not_escape_forward_slashes(): void
    {
        // Matches JS's JSON.stringify (never escapes `/`) — plain PHP json_encode does by default,
        // which would diverge from the client-side printer's output for the common href/src shape.
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'a', 'isComponent' => false, 'dynamic' => false, 'children' => [],
                'props' => [['name' => 'href', 'kind' => 'string', 'value' => 'https://example.test/docs']],
            ],
        ]);

        $this->assertStringContainsString('href="https://example.test/docs"', $out);
    }

    public function test_multiple_top_level_roots_are_separated_by_statement_terminators(): void
    {
        // A real page is never single-root — bare adjacent JSX with no separator is a parse error on
        // the JS side ("Adjacent JSX elements must be wrapped..."). Mirrors the identical JS-side
        // regression test in blockdoc/json.test.ts.
        $out = JsonDocPrinter::print([
            ['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [['kind' => 'text', 'value' => 'first']]],
            ['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [['kind' => 'text', 'value' => 'second']]],
        ]);

        $this->assertSame("<section>first</section>;\n<section>second</section>;", $out);
    }

    public function test_an_expression_prop_re_wraps_its_stripped_source(): void
    {
        $out = JsonDocPrinter::print([
            [
                'kind' => 'block', 'name' => 'X', 'isComponent' => true, 'dynamic' => false, 'children' => [],
                'props' => [['name' => 'onClick', 'kind' => 'expression', 'value' => 'handleClick']],
            ],
        ]);

        $this->assertStringContainsString('onClick={handleClick}', $out);
    }

    public function test_print_module_wraps_the_document_in_a_default_exported_component(): void
    {
        // G2-BEAM-AUTHOR-ENTRY, measured on beam.test 2026-09-11. `print()` emits bare JSX statements
        // for the disk mirror, which `blockdoc`'s `parse()` must read back — and which esbuild happily
        // compiles to a module that exports NOTHING. `<EntryBody>` imports the artifact and reads
        // `default` off it, so an owner's saved page rendered as "not compiled yet" while the compile
        // reported success. Two consumers, two shapes.
        $out = JsonDocPrinter::printModule([
            ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Authored']]],
            ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Body']]],
        ]);

        $this->assertStringContainsString('export default function Page()', $out);
        $this->assertStringContainsString('<h2>Authored</h2>', $out);
        $this->assertStringContainsString('<p>Body</p>', $out);
        // One fragment, not one statement per root: adjacent roots are the normal shape for a page and
        // a `return` takes one expression.
        $this->assertStringContainsString('<>', $out);
        $this->assertStringNotContainsString('</h2>;', $out);
    }

    public function test_print_module_of_an_emptied_document_still_exports_a_component(): void
    {
        // Emptying a page is a legitimate edit. A module with no default export would reach the reader
        // as a FAILURE ("not compiled yet") rather than as an empty page.
        $out = JsonDocPrinter::printModule([]);

        $this->assertStringContainsString('export default function Page()', $out);
        $this->assertStringContainsString('return null;', $out);
    }

    public function test_print_module_resolves_component_islands_through_the_components_prop(): void
    {
        // Island names are BARE IDENTIFIERS in printed JSX and an artifact imports nothing. `<EntryBody>`
        // hands a compiled body its host registry on a `components` prop; this is what connects them, so
        // the canvas and the artifact resolve the SAME name through the SAME map. Measured on beam.test
        // 2026-09-11: without it the saved page threw `DemoHero is not defined` and took the WHOLE page
        // down, not just the body.
        $out = JsonDocPrinter::printModule([
            [
                'kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [
                    ['kind' => 'block', 'name' => 'DemoHero', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []],
                    ['kind' => 'block', 'name' => 'DemoHero', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []],
                    ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Hi']]],
                ],
            ],
        ]);

        $this->assertStringContainsString('Page({ components = {} })', $out);
        // Once, not once per occurrence, and plain tags are never destructured.
        $this->assertStringContainsString('const { DemoHero} = components;', $out);
        $this->assertSame(1, substr_count($out, '= components;'));
        $this->assertStringNotContainsString('h2 }', $out);
    }

    public function test_print_module_takes_no_components_prop_when_the_document_has_no_islands(): void
    {
        // A signature that promises a prop nothing reads is a claim; omit it.
        $out = JsonDocPrinter::printModule([
            ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Plain']]],
        ]);

        $this->assertStringContainsString('export default function Page()', $out);
        $this->assertStringNotContainsString('components', $out);
    }

    public function test_component_names_reports_each_island_once_in_first_seen_order(): void
    {
        $names = JsonDocPrinter::componentNames([
            ['kind' => 'block', 'name' => 'Second', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => [
                ['kind' => 'block', 'name' => 'First', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []],
                ['kind' => 'text', 'value' => 'x'],
            ]],
            ['kind' => 'block', 'name' => 'Second', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []],
            ['kind' => 'opaque', 'reason' => 'map', 'source' => '{x.map(() => <li/>)}'],
        ]);

        $this->assertSame(['Second', 'First'], $names);
    }
}
