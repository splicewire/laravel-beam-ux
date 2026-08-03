<?php

namespace Splicewire\Beam\Ux\Format;

use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **body-language axis** of a {@see BeamUxEntry} — how the body is
 * authored, which {@see BodyCodec} compiles/renders it, and which file
 * extension it materializes to (ADR-0164). A **sibling axis to** {@see UxType},
 * NOT a fifth `type` value: `{type, format}` composes as a matrix (an mdx page vs. an mdx component).
 *
 * The set is open-ended by intent ("`tsx | mdx | …`") — the codec registry dispatches on the string
 * value, so a host can register a codec for a format this enum does not yet name. These two are the
 * S1 seed set.
 */
enum UxFormat: string
{
    /** TSX/JSX — the default. Its codec honors `body_style` (auto-import preamble). */
    case Tsx = 'tsx';

    /** MDX — folded in from the free-tier `laravel-beam-mdx` arm; `body_style` is meaningless here. */
    case Mdx = 'mdx';

    /** The file extension the format materializes to (drives disk placement, S2). */
    public function extension(): string
    {
        return $this->value;
    }
}
