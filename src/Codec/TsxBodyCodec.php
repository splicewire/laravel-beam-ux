<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The **TSX codec** (beam-ux-owned, no arm folded in). Encodes JSX/TSX source into a structured particle body and
 * owns the one place {@see BodyStyle} lives (ADR-0164): a `body_style: full` entry gets the auto-import
 * **preamble** injected (a self-contained component with ceremony); a `body_style: inline` entry — the
 * old "node" — is left bare. `body_style` is a tsx-codec-local flavor; it exists nowhere else.
 *
 * The preamble is injected *deterministically* at encode time and captured as a distinct body facet, so
 * a decode round-trips to the author's original source (bare JSX) regardless of style — the preamble is
 * a derived artifact of `full`, never part of what the author typed.
 */
class TsxBodyCodec implements BodyCodec
{
    /** The particle-payload key holding the author's raw JSX/TSX source. */
    public const SOURCE_KEY = 'source';

    /** The particle-payload key holding the resolved body style. */
    public const STYLE_KEY = 'body_style';

    /** The particle-payload key holding the injected auto-import preamble (null for inline). */
    public const PREAMBLE_KEY = 'preamble';

    /** The auto-import preamble injected for `body_style: full`. */
    public const AUTO_IMPORT_PREAMBLE = "import { jsx as _jsx } from '@splicewire/beam-ux/runtime';";

    public function format(): UxFormat
    {
        return UxFormat::Tsx;
    }

    public function extension(): string
    {
        return UxFormat::Tsx->extension();
    }

    public function encode(string $raw, ?BodyStyle $style = null): array
    {
        // Default to full — a component authored without a style is a self-contained one.
        $style ??= BodyStyle::Full;

        return [
            self::SOURCE_KEY => $raw,
            self::STYLE_KEY => $style->value,
            // The preamble is injected ONLY for full; inline compiles the JSX without ceremony.
            self::PREAMBLE_KEY => $style === BodyStyle::Full ? self::AUTO_IMPORT_PREAMBLE : null,
        ];
    }

    public function decode(array $body): string
    {
        // Two DISTINCT body shapes carry the `tsx` format: `{source: '…', body_style, preamble}` — an
        // entry registered from a raw .tsx FILE (RegisterEntriesFromDisk) — round-trips through its own
        // `source` key untouched (the preamble is a derived artifact of `full`, never part of what the
        // author typed). A canvas/blockdoc-AUTHORED entry's body is instead a plain JsonNode[] LIST
        // (`[{kind:'block',…}, …]`, no `source` key at all — the two are mutually exclusive shapes for
        // the same format, so `array_is_list()` cleanly discriminates) — that prints through
        // {@see JsonDocPrinter}, the server-side port of the canvas's own client-side "Source" printer.
        if (array_is_list($body)) {
            return JsonDocPrinter::print($body);
        }

        return (string) ($body[self::SOURCE_KEY] ?? '');
    }
}
