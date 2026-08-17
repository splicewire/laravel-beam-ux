<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codec\TsxBodyCodec;

/**
 * `decode()` must discriminate the TWO distinct body shapes a `tsx`-format entry can carry (found live,
 * splicewire's `/beam`): `{source: '…'}` for a disk-registered entry, vs a plain `JsonNode[]` list for a
 * canvas/blockdoc-authored one. Before this fix, `decode()` only handled the former — a blockdoc body has
 * no `source` key, so PlacedDiskMirror's materialize-on-save silently wrote empty (0-byte) files for
 * every canvas-edited page.
 */
class TsxBodyCodecTest extends TestCase
{
    public function test_decodes_a_raw_source_envelope_through_its_source_key(): void
    {
        $codec = new TsxBodyCodec;

        $decoded = $codec->decode(['source' => '<div>hi</div>', 'body_style' => 'inline', 'preamble' => null]);

        $this->assertSame('<div>hi</div>', $decoded);
    }

    public function test_decodes_a_blockdoc_body_through_the_json_doc_printer(): void
    {
        $codec = new TsxBodyCodec;

        $decoded = $codec->decode([
            [
                'kind' => 'block', 'name' => 'h1', 'isComponent' => false, 'dynamic' => false, 'props' => [],
                'children' => [['kind' => 'text', 'value' => 'Hello']],
            ],
        ]);

        $this->assertSame('<h1>Hello</h1>', $decoded);
    }

    public function test_an_empty_blockdoc_body_decodes_to_an_empty_string_not_a_crash(): void
    {
        $codec = new TsxBodyCodec;

        $this->assertSame('', $codec->decode([]));
    }
}
