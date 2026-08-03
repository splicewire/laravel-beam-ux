<?php

namespace Splicewire\Beam\Ux\Format;

/**
 * A **TSX-codec-local flavor** (ADR-0164), NOT a structural axis: it governs whether the TSX codec
 * injects the auto-import preamble (`full`) or leaves the JSX bare (`inline` — the old "node"). It is
 * **meaningless for `mdx`** and any non-tsx format; those codecs ignore it.
 *
 * This is the demotion the format axis forced: what was once a first-class entry flag is now scoped
 * inside one codec, because the preamble is a tsx compilation concern, not a composition kind.
 */
enum BodyStyle: string
{
    /** Inject the auto-import preamble — a self-contained component with ceremony. */
    case Full = 'full';

    /** Leave the JSX bare — "write JSX without ceremony" (the old node). */
    case Inline = 'inline';
}
