<?php

namespace Splicewire\Beam\Ux\Format;

use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Codec\UxFormatCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The **body-language axis** of a {@see BeamUxEntry} — how the body is
 * authored, which {@see BodyCodec} compiles/renders it, and which file
 * extension it materializes to (ADR-0164). A **sibling axis to** {@see UxType},
 * NOT a fifth `type` value: `{type, format}` composes as a matrix (an mdx page vs. an mdx component).
 *
 * The set is open-ended by intent ("`tsx | mdx | …`") — `implements` {@see UxFormatCase} is what
 * actually makes that true (see its own docblock): `BodyCodec::format()` is typed to the INTERFACE, so
 * a host's own bespoke enum can satisfy it without ever touching this class. This enum is the
 * generated (or hand-curated, today) "known so far" set, not a ceiling — Tsx/Mdx/Css are the S1 seed.
 */
enum UxFormat: string implements UxFormatCase
{
    /** TSX/JSX — the default. Its codec honors `body_style` (auto-import preamble). */
    case Tsx = 'tsx';

    /** MDX — folded in from the free-tier `laravel-beam-mdx` arm; `body_style` is meaningless here. */
    case Mdx = 'mdx';

    /** CSS custom properties — a {@see \Splicewire\Beam\Ux\Type\UxType::Theme} entry's body materializes
     * as a `:root { --token: value; }` block. `body_style` is meaningless here (tsx-only). */
    case Css = 'css';

    // NOTE: there is intentionally NO `json` format. A structural page's body was briefly mirrored to
    // disk as verbatim `.json` — the category error ADR-0164's "NEXT" slice retired: json is a
    // serialization, not a SOURCE language. A structural page stays `format: tsx`, the same extension a
    // hand-authored component uses, disambiguated by `{type, body-shape}` — never a fifth format value.

    /** The file extension the format materializes to (drives disk placement, S2). */
    public function extension(): string
    {
        return $this->value;
    }
}
