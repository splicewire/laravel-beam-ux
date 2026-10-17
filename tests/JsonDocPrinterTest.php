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

    /**
     * A literal prop must not print code. The printer emitted a prop's NAME and a `number` prop's VALUE
     * verbatim, so `{name: 'onClick={() => steal()} x'}` or `{kind: 'number', value: '0} onMouseOver={…'}`
     * put an expression into the module with no `expression` prop anywhere (EP-07a probe P3/P4, launch
     * 2026-10-17). In every realm: a central author has expression props for code, a literal is never one.
     */
    public function test_a_prop_name_that_is_not_a_jsx_attribute_name_is_refused(): void
    {
        foreach (['onClick={() => steal()} data-x', 'a b', 'x"', 'x=1', '{x}', '1abc', '', 'a>'] as $name) {
            try {
                JsonDocPrinter::print([[
                    'kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'children' => [],
                    'props' => [['name' => $name, 'kind' => 'boolean-shorthand', 'value' => true]],
                ]]);
                $this->fail("the prop name [$name] was printed");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('attribute name', $e->getMessage());
            }
        }
    }

    public function test_a_number_prop_must_carry_a_finite_number(): void
    {
        // Code in a string, non-numbers, non-finite numbers, and the numbers PHP and JS print differently:
        // -0, an unsafe integer, and exponent forms (review-r1, 2026-10-17).
        foreach (['0} onMouseOver={() => steal()} data-m={0', '3', true, null, NAN, INF, -INF, -0.0, 9007199254740992, -9007199254740992, 1e20, 1e17, 1e-6, 1.5e-7] as $value) {
            try {
                JsonDocPrinter::print([[
                    'kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'children' => [],
                    'props' => [['name' => 'data-n', 'kind' => 'number', 'value' => $value]],
                ]]);
                $this->fail('the number value '.json_encode($value).' was printed');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('number', $e->getMessage());
            }
        }
    }

    /** Each admitted number prints exactly as the JS printer's `{String(n)}` would. */
    public function test_an_admitted_number_prints_as_javascript_prints_it(): void
    {
        $cases = [[3, '3'], [3.0, '3'], [-1, '-1'], [1.5, '1.5'], [0.0, '0'], [0.0001, '0.0001'], [0.1 + 0.2, '0.30000000000000004'], [9007199254740991, '9007199254740991'], [123456789.25, '123456789.25'], [1e16, '10000000000000000']];

        foreach ($cases as [$value, $js]) {
            $out = JsonDocPrinter::print([[
                'kind' => 'block', 'name' => 'p', 'isComponent' => false, 'dynamic' => false, 'children' => [],
                'props' => [['name' => 'data-n', 'kind' => 'number', 'value' => $value]],
            ]]);

            $this->assertStringContainsString("data-n={{$js}}", $out, var_export($value, true));
        }
    }

    public function test_real_attribute_names_and_numbers_still_print(): void
    {
        $out = JsonDocPrinter::print([[
            'kind' => 'block', 'name' => 'svg', 'isComponent' => false, 'dynamic' => false, 'children' => [],
            'props' => [
                ['name' => 'aria-label', 'kind' => 'string', 'value' => 'Logo'],
                ['name' => 'data-x', 'kind' => 'boolean-shorthand', 'value' => true],
                ['name' => 'xlink:href', 'kind' => 'string', 'value' => '#a'],
                ['name' => 'strokeWidth', 'kind' => 'number', 'value' => 1.5],
                ['name' => 'tabIndex', 'kind' => 'number', 'value' => -1],
                ['name' => 'onClick', 'kind' => 'expression', 'value' => 'handle'],
            ],
        ]]);

        $this->assertStringContainsString('aria-label="Logo"', $out);
        $this->assertMatchesRegularExpression('/ data-x\b/', $out);
        $this->assertStringContainsString('xlink:href="#a"', $out);
        $this->assertStringContainsString('strokeWidth={1.5}', $out);
        $this->assertStringContainsString('tabIndex={-1}', $out);
        $this->assertStringContainsString('onClick={handle}', $out);
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

    public function test_print_module_emits_style_as_a_react_object_while_the_mirror_keeps_the_string(): void
    {
        // Measured on beam.test 2026-09-11: the saved page rendered `style="max-width:900px"`, React
        // refused the string (minified error #62) and the whole page died. The canvas never had this
        // problem — `blockToProps` runs the body's style through `parseStyle` — so an artifact that does
        // not is the lens that disagrees, and it must agree. kebab->camel is part of it: React ignores
        // `max-width` and warns.
        $node = [
            'kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'children' => [],
            'props' => [['name' => 'style', 'kind' => 'string', 'value' => 'max-width: 900px; margin: 0 auto']],
        ];

        $module = JsonDocPrinter::printModule([$node]);

        $this->assertStringContainsString('style={{"maxWidth":"900px","margin":"0 auto"}}', $module);
        $this->assertStringNotContainsString('style="', $module);

        // The MIRROR keeps the authored attribute: that file is read back by blockdoc's `parse()`.
        $this->assertStringContainsString('style="max-width: 900px; margin: 0 auto"', JsonDocPrinter::print([$node]));
    }

    public function test_print_module_drops_an_empty_style_rather_than_emitting_an_empty_object(): void
    {
        $out = JsonDocPrinter::printModule([[
            'kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'children' => [],
            'props' => [['name' => 'style', 'kind' => 'string', 'value' => '  ']],
        ]]);

        $this->assertStringNotContainsString('style', $out);
    }

    public function test_a_block_carries_a_stable_id_that_the_printer_leaves_out_of_source(): void
    {
        // ADR-0228 d3: every block has a stable UUIDv7 id (as in blockdoc); the TSX printer leaves ids
        // out of source, and the id round-trips on the typed node.
        $id = '0192f0a0-0000-7000-8000-000000000001';
        $doc = [[
            'kind' => 'block', 'id' => $id, 'name' => 'div', 'isComponent' => false, 'dynamic' => false,
            'props' => [], 'children' => [['kind' => 'text', 'value' => 'hi']],
        ]];

        // The id is carried on the typed node (a declared field, not a stripped array key)...
        $node = \Splicewire\Beam\Ux\JsonDoc\JsonNode::from($doc[0]);
        $this->assertInstanceOf(\Splicewire\Beam\Ux\JsonDoc\JsonBlockNode::class, $node);
        $this->assertSame($id, $node->id);

        // ...but never appears in the printed source (ids live in the body, not the .tsx).
        $this->assertStringNotContainsString($id, JsonDocPrinter::print($doc));
        $this->assertSame('<div>hi</div>;', JsonDocPrinter::print($doc));
    }

    public function test_golden_a_rich_document_prints_byte_for_byte(): void
    {
        // ADR-0228 d2: the printer now consumes the hydrated JsonNode tree, not raw array keys. This
        // golden pins the full output byte-for-byte across every feature at once — scalar + string +
        // expression props, a slash-safe URL, an escaped text leaf, an inline leaf, a component island,
        // an opaque node re-emitted verbatim, a fragment root, multi-root statement terminators, and the
        // module's island destructure + single-fragment wrap — so the typed refactor cannot drift.
        $doc = [
            ['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false,
                'props' => [
                    ['name' => 'id', 'kind' => 'string', 'value' => 'hero'],
                    ['name' => 'cols', 'kind' => 'number', 'value' => 3],
                    ['name' => 'live', 'kind' => 'boolean', 'value' => true],
                    ['name' => 'open', 'kind' => 'boolean-shorthand', 'value' => true],
                    ['name' => 'href', 'kind' => 'string', 'value' => 'https://x.test/a'],
                    ['name' => 'onClick', 'kind' => 'expression', 'value' => 'go'],
                ],
                'children' => [
                    ['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'A < B']]],
                    ['kind' => 'block', 'name' => 'Hero', 'isComponent' => true, 'dynamic' => false, 'props' => [], 'children' => []],
                    ['kind' => 'opaque', 'reason' => 'map', 'source' => '{xs.map(x => <li>{x}</li>)}'],
                ]],
            ['kind' => 'block', 'name' => null, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ];

        $expectedPrint = implode("\n", [
            '<section id="hero" cols={3} live={true} open href="https://x.test/a" onClick={go}>',
            "  <h2>A {'<'} B</h2>",
            '  <Hero />',
            '  {xs.map(x => <li>{x}</li>)}',
            '</section>;',
            '<></>;',
        ]);

        $this->assertSame($expectedPrint, JsonDocPrinter::print($doc));

        $expectedModule = implode("\n", [
            'export default function Page({ components = {} }) {',
            '  const { Hero} = components;',
            '  return (',
            '    <>',
            '      <section id="hero" cols={3} live={true} open href="https://x.test/a" onClick={go}>',
            "        <h2>A {'<'} B</h2>",
            '        <Hero />',
            '        {xs.map(x => <li>{x}</li>)}',
            '      </section>',
            '      <></>',
            '    </>',
            '  );',
            '}',
            '',
        ]);

        $this->assertSame($expectedModule, JsonDocPrinter::printModule($doc));
    }
}
