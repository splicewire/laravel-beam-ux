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

        $this->assertSame('<h2>Title</h2>', $out);
    }

    public function test_a_childless_element_prints_self_closing(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'block', 'name' => 'br', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ]);

        $this->assertSame('<br />', $out);
    }

    public function test_a_fragment_root_has_no_tag_name(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'block', 'name' => null, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ]);

        $this->assertSame('<></>', $out);
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

        $this->assertSame("<section>\n  <h1>Heading</h1>\n  <p>Body</p>\n</section>", $out);
    }

    public function test_an_opaque_node_re_emits_its_source_verbatim(): void
    {
        $out = JsonDocPrinter::print([
            ['kind' => 'opaque', 'reason' => 'map', 'source' => '{items.map(i => <li key={i}>{i}</li>)}'],
        ]);

        $this->assertSame('{items.map(i => <li key={i}>{i}</li>)}', $out);
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
}
