<?php

namespace Splicewire\Beam\Ux\Type;

use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **single structural axis** of a {@see BeamUxEntry} — what it does
 * in the UI composition graph (charter S1, `beamux-build/issues/02`). This is the ENFORCED taxonomy:
 * an entry is exactly one of these four, no more.
 *
 * It is deliberately **orthogonal to `format`** ({@see UxFormat}, the body
 * language / codec): the two compose as a `{type, format}` matrix, so an `mdx` **page** and an `mdx`
 * **component** are both expressible (ADR-0164). `mdx`/`tsx` are body FORMATS, never a fifth `type`;
 * "node" (bare JSX) is a `component` in `body_style: inline`, never a fifth `type` either.
 */
enum UxType: string
{
    /** Wraps other renderings — a chrome shell, not a component. */
    case Layout = 'layout';

    /** A slot-bearing scaffold a page/rendering fills. */
    case Template = 'template';

    /** A routable rendering (an article, a screen). */
    case Page = 'page';

    /** A reusable, mountable unit. `body_style: inline` expresses the old "node" (bare JSX). */
    case Component = 'component';
}
