<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Mdx\Lens\MdxLens;

/**
 * ADR-0233 d1 — the MDX codec of a host whose MDX pages hold blocks (`beam.ux.mdx.holds_blocks`). It
 * {@see AcceptsJsonDoc}, so an MDX entry is blocks-kind and the canvas saves onto it as onto a `tsx` page,
 * and a JsonDoc body prints back as canonical MDX through the MDX Lens's printer (`splicewire/laravel-beam-mdx`,
 * run in Node). A body still in the `{frontmatter, content}` source shape, an entry this host has not yet
 * converted (d9), decodes as before.
 *
 * The printed text is the page body only: frontmatter is the page's settings, held by its version (d3).
 */
class MdxBlocksBodyCodec extends MdxBodyCodec implements AcceptsJsonDoc
{
    public function decode(array $body): string
    {
        if (array_is_list($body)) {
            /** @var array<int, array<string, mixed>> $body */
            return app(MdxLens::class)->print($body);
        }

        return parent::decode($body);
    }
}
