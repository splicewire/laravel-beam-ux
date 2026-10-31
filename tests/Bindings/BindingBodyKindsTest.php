<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Ux\Bindings\BindingNodeBaseline;
use Splicewire\Beam\Ux\Bindings\SchemaBindingImpact;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;

final class BindingBodyKindsTest extends TestCase
{
    public function test_published_mdx_source_has_no_node_bindings_but_keeps_its_record_page_declaration(): void
    {
        $body = (new MdxBodyCodec)->encode("---\ntitle: Published MDX\n---\n# Content\n");
        $before = $body;
        $impact = new SchemaBindingImpact;

        self::assertSame([], $impact->collections($body));
        self::assertSame(['articles'], $impact->collections($body, ['collection' => 'articles', 'field' => 'slug']));
        self::assertSame($before, $body);
    }

    public function test_tsx_source_does_not_grandfather_a_new_canvas_binding(): void
    {
        $body = (new TsxBodyCodec)->encode('<main>Source page</main>');
        $before = $body;
        $baseline = new BindingNodeBaseline($body);

        self::assertFalse($baseline->unchanged(['kind' => 'text', 'value' => '', 'field' => 'fields.retired'], '/0', []));
        self::assertSame($before, $body);
    }

    public function test_jsondoc_bindings_in_children_slots_and_empty_branches_are_still_discovered(): void
    {
        $body = [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'articles',
            'empty' => [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'fallback']]]],
            'children' => [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'children']]],
            'slots' => ['aside' => [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'aside']]]]]];

        self::assertSame(['pages', 'articles', 'fallback', 'children', 'aside'],
            (new SchemaBindingImpact)->collections($body, ['collection' => 'pages', 'field' => 'slug']));
    }

    public function test_only_exact_unchanged_jsondoc_nodes_keep_their_baseline(): void
    {
        $node = ['kind' => 'text', 'value' => '', 'field' => 'fields.retired'];
        $baseline = new BindingNodeBaseline([$node]);

        self::assertTrue($baseline->unchanged($node, '/0', []));
        self::assertFalse($baseline->unchanged([...$node, 'field' => 'fields.other'], '/0', []));
        self::assertFalse($baseline->unchanged($node, '/1', []));
        self::assertFalse($baseline->unchanged($node, '/0', [['when' => ['all' => []]]]));
    }
}
