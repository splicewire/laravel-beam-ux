<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * The **JSON codec** (ADR-0164) — the passthrough codec for a **structural** body whose disk file IS the
 * particle body verbatim. The canonical consumer is a **Puck page**: the body is a Puck `Data` document
 * (`{root, content, zones}`), already the structured array beam-core versions, so there is no
 * raw-source ⇄ array compile step the way a `.tsx` / `.mdx` body needs.
 *
 * `encode` parses the raw JSON text into the array body; `decode` pretty-prints the array body back to
 * JSON text — inverses, so a `.json` body registers, versions, and round-trips exactly as a tsx/mdx one
 * does. {@see BodyStyle} is **meaningless here** (a tsx-only preamble concern) and ignored.
 *
 * Why this exists: a Puck page's body is JSON, not source text — materializing it through the mdx/tsx
 * codecs would mangle it. This codec makes the disk file a git-trackable `.json` of the Puck Data itself.
 */
class JsonBodyCodec implements BodyCodec
{
    public function format(): UxFormat
    {
        return UxFormat::Json;
    }

    public function extension(): string
    {
        return UxFormat::Json->extension();
    }

    public function encode(string $raw, ?BodyStyle $style = null): array
    {
        // $style is ignored — body_style is a tsx-codec-local flavor, meaningless for json.
        $decoded = json_decode($raw === '' ? '{}' : $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function decode(array $body): string
    {
        return (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
