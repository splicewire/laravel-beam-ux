<?php

namespace Splicewire\Beam\Ux\Type;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **single structural axis** of a {@see BeamUxEntry} — what it does
 * in the UI composition graph (charter S1, `beamux-build/issues/02`). This is the ENFORCED taxonomy:
 * an entry is exactly one of these five.
 *
 * It is deliberately **orthogonal to `format`** ({@see UxFormat}, the body
 * language / codec): the two compose as a `{type, format}` matrix, so an `mdx` **page** and an `mdx`
 * **component** are both expressible (ADR-0164). `mdx`/`tsx` are body FORMATS, never a fifth `type`;
 * "node" (bare JSX) is a `component` in `body_style: inline`, never a fifth `type` either.
 *
 * `Theme` (theme-entries-and-authoring ticket 05) is the first genuinely structural addition since
 * the original four — a theme override entry, `Splicewire\Beam\Ux\Theme\ThemeResolver`'s central/
 * tenant tier of the `{canvas, shell, site}` cascade. Non-routable (like `Component`), but never
 * mountable in the visual editor's canvas the way a `Component` is — its body is theme TOKENS, not a
 * composable tree.
 *
 * @api
 */
enum UxType: string implements ProvidesEnumLabel
{
    /** Wraps other renderings — a chrome shell, not a component. */
    case Layout = 'layout';

    /** A slot-bearing scaffold a page/rendering fills. */
    case Template = 'template';

    /** A routable rendering (an article, a screen). */
    case Page = 'page';

    /** A reusable, mountable unit. `body_style: inline` expresses the old "node" (bare JSX). */
    case Component = 'component';

    /** A theme override (central or tenant tier) — `Splicewire\Beam\Ux\Theme\ThemeResolver`. */
    case Theme = 'theme';

    /**
     * localization 23c: the type's declared (English) label, which is the value itself: exactly what forms and lists
     * showed before (no `enumNames`, so the backing value). The schema generator emits it as `enumNames` (an enum-typed
     * property, and the string properties that name this enum through {@see \Splicewire\Beam\Ux\Schema\EnumValueLabels}),
     * and beam's SchemaText translates it ONCE, per request, from the derived key
     * `beam-ux::enums.Splicewire/Beam/Ux/Type/UxType.cases.<Case>.label` (lang/en/enums.php). Declared English here,
     * so no second path translates (or pseudo-marks) it again.
     */
    public function label(): string
    {
        return $this->value;
    }
}
