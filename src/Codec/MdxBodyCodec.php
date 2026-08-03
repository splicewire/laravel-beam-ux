<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The **MDX codec** (paid `splicewire/*` dispatch, free-tier engine). This adapter is the paid side of
 * the vendor seam — the `BodyCodec` port + its registration on the entry — while the actual body
 * machinery is **folded in from the free-tier `laravel-beam-mdx` arm** ({@see MdxBody}), not deleted
 * (ADR-0164). That keeps the paid/free split honest: the dispatch is paid, the mdx engine is free, and
 * beam-ux depends DOWN on beam-mdx to compose it.
 *
 * MDX is thus the *second consumer* proving the particle/version/storage machinery is format-generic:
 * an mdx body versions and round-trips exactly as a tsx body does. {@see BodyStyle} is **meaningless
 * here** and ignored — the auto-import preamble is a tsx-only concern.
 */
class MdxBodyCodec implements BodyCodec
{
    public function format(): UxFormat
    {
        return UxFormat::Mdx;
    }

    public function extension(): string
    {
        return UxFormat::Mdx->extension();
    }

    public function encode(string $raw, ?BodyStyle $style = null): array
    {
        // $style is ignored — body_style is a tsx-codec-local flavor, meaningless for mdx.
        return MdxBody::encode($raw);
    }

    public function decode(array $body): string
    {
        return MdxBody::decode($body);
    }
}
