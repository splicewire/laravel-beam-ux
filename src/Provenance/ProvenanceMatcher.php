<?php

namespace Splicewire\Beam\Ux\Provenance;

use Splicewire\Beam\Ux\Codec\BodyCodec;

/**
 * Does a stored body come from a template (DOCS-06b)? EXACT, apart from the template's declared `{{ token }}` spans,
 * which a seeder filled with host values: everything else must equal the template as the codec would have stored it.
 * A row edited by even one character outside a token span does not match, so the backfill never mistakes an edited row
 * for a pristine one and the re-assert never overwrites it (lead 08:33Z). A token span matches within one line.
 */
final class ProvenanceMatcher
{
    private const TOKEN = '/\{\{\s*[A-Za-z0-9_]+\s*\}\}/';

    public static function matches(string $template, string $stored, BodyCodec $codec): bool
    {
        return self::captures($template, $stored, $codec) !== null;
    }

    /**
     * The value each `{{ token }}` span holds in the stored body, keyed by token name, or null when it does not match.
     * The dry run prints them (review-r1): a token's line is the one place an edit could still read as pristine, so a
     * person checks the captured values are machine-written (a URL, a brand) before the backfill is applied.
     *
     * @return array<string, string>|null
     */
    public static function captures(string $template, string $stored, BodyCodec $codec): ?array
    {
        preg_match_all(self::TOKEN, $template, $names);
        $names = array_map(fn (string $t): string => trim($t, "{} \t"), $names[0]);
        $i = 0;
        $sentinel = static fn (int $n): string => "\u{E000}T{$n}\u{E001}";
        $marked = preg_replace_callback(self::TOKEN, function () use (&$i, $sentinel): string {
            return $sentinel($i++);
        }, $template);

        $asStored = Provenance::asStored($codec, (string) $marked);

        if ($i === 0) {
            return $asStored === $stored ? [] : null;
        }

        // A token span fills ONE line. Allowed to span lines, a token occupying a body's middle (`{{ mcp_servers }}`)
        // matched ANY body sharing the template's first and last lines, an edited one included (measured, red first).
        // Every token on the rows found in the field is a one-line value (a URL, a brand); a row seeded from a template
        // with a multi-line token is stamped at seed time and never needs the backfill.
        $order = [];
        $pattern = preg_replace_callback('/\x{E000}T(\d+)\x{E001}/u', function (array $m) use (&$order): string {
            $order[] = (int) $m[1];

            return '([^\n]*)';
        }, preg_quote($asStored, '#'));

        if (preg_match('#\A'.$pattern.'\z#u', $stored, $m) !== 1) {
            return null;
        }
        $captured = [];
        foreach ($order as $k => $index) {
            $captured[$names[$index] ?? 'token'.$index] = $m[$k + 1];
        }

        return $captured;
    }
}
