<?php

namespace Splicewire\Beam\Ux\Body;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * ADR-0228 d1 — the KIND of body an entry declares, independent of the data it currently holds.
 *
 * The entry's type + format decide the kind; it is never sniffed from the stored payload, which is
 * exactly what d1 retires (`array_is_list` / `JsonDocShape::is` stop being discriminators). One input
 * serves each kind (d10): blocks a declared JsonDoc, source a `{ source }` write, a form a schema-checked
 * save.
 */
#[TypeScript]
enum BodyKind: string
{
    /** A JsonDoc the canvas edits (a `tsx` page). `AcceptsJsonDoc` comes to mean exactly this kind. */
    case Blocks = 'blocks';

    /** Author-edited source text a codec round-trips (a component, an mdx page until ticket 15). */
    case Source = 'source';

    /** Fields checked against the entry's own `schema` and written through the codec (a theme). */
    case Form = 'form';
}
