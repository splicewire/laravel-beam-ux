<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The **body codec port** — dispatched on {@see UxFormat} (ADR-0164). A codec is the translation seam
 * between the **raw source text** an author writes (a `.tsx` / `.mdx` file body) and the **structured
 * particle body** (the `array` payload beam-core's `ParticleWriter` versions and stores). It is the
 * paid `splicewire/*` side of the vendor seam: the *dispatch* on the entry is paid; a codec MAY delegate
 * its actual machinery to a free-tier arm (the MDX codec folds in `laravel-beam-mdx`).
 *
 * `encode` + `decode` are inverses: a raw body encoded then decoded round-trips, which is how an entry
 * of any format rides the same particle/version/storage treatment.
 */
interface BodyCodec
{
    /** The format this codec handles — the registry keys on `format()->value`. */
    public function format(): UxFormat;

    /** The file extension bodies of this format materialize to (derives from the format, S2). */
    public function extension(): string;

    /**
     * Encode raw source text into a structured particle body payload.
     *
     * @param  BodyStyle|null  $style  the TSX-codec-local flavor (auto-import preamble); ignored by
     *                                 codecs for which it is meaningless (e.g. mdx).
     * @return array<string, mixed>
     */
    public function encode(string $raw, ?BodyStyle $style = null): array;

    /**
     * Decode a structured particle body payload back into raw source text (the inverse of encode).
     *
     * @param  array<string, mixed>  $body
     */
    public function decode(array $body): string;
}
