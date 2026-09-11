<?php

namespace Splicewire\Beam\Ux\Codec;

/**
 * A **PHP port** of `@splicewire/beam-ux/blockdoc/json.ts`'s `jsonToTsx()` — the pure, Babel-free
 * `JsonNode[]` → TSX-source printer the canvas editor's own client-side "Source" toggle already uses.
 * Kept a behavioral mirror (same leaf-inlining rule, same prop-kind switch, same text escaping) so a
 * canvas-authored body decodes to the SAME source whether printed client-side or here — this is the
 * server-side half: {@see TsxBodyCodec::decode()} calls it for a blockdoc-shaped body (`PlacedDiskMirror`'s
 * materialize-on-save path runs entirely server-side, so it can't reach the JS printer directly).
 *
 * A `JsonNode` here is a plain associative array (the particle payload's raw decoded JSON), discriminated
 * by `kind`: `'block'` (`name`/`isComponent`/`props`/`children`/`dynamic`), `'text'` (`value`), or
 * `'opaque'` (`source`, carried verbatim — a sealed dynamic island the static lens couldn't decompose).
 */
class JsonDocPrinter
{
    private const INDENT = '  ';

    /**
     * Print a JSON document (the doc-root array) to indented JSX source. Each root prints as its own
     * top-level JSX expression STATEMENT (a trailing `;`) — bare adjacent JSX elements/fragments with
     * no separator are a parse error ("Adjacent JSX elements must be wrapped in an enclosing tag"), so
     * a multi-root document (the normal shape for a real page: several top-level sections) needs an
     * explicit statement terminator between roots to stay re-parseable by
     * `@splicewire/beam-ux/blockdoc`'s `parse()`. Mirrors the identical fix in that package's own JS
     * printer, `jsonToTsx()` (`blockdoc/json.ts`) — found live: this package's materialize-on-save
     * disk mirror was producing files that the JS-side parser couldn't actually re-parse.
     *
     * @param  array<int, array<string, mixed>>  $doc
     */
    public static function print(array $doc, int $depth = 0): string
    {
        return implode("\n", array_map(fn (array $n) => self::printNode($n, $depth).';', $doc));
    }

    /**
     * Print a JSON document as a **compilable ES module** — a default-exported component returning the
     * whole tree in one fragment.
     *
     * {@see print()} deliberately emits bare JSX STATEMENTS, because its consumer is the disk mirror and
     * `@splicewire/beam-ux/blockdoc`'s `parse()` has to read that file back. The COMPILER's consumer is
     * different and irreconcilable with it: `EntryArtifactController` serves a module that
     * `<EntryBody>` imports and calls, reading `default` off what it returns. Bare statements are a
     * valid file and an empty module — esbuild compiles them, nothing is exported, and the reader gets
     * a module with no `default`.
     *
     * Measured on beam.test 2026-09-11 (G2-BEAM-AUTHOR-ENTRY): an owner authored `/`, Save reported
     * "Saved" truthfully, the artifact compiled and was served 200 — and it was
     * `export default function (runtime) { … return module.exports; }` with an empty `module.exports`,
     * so the reader rendered the "not compiled yet" state over a body that had compiled fine. A
     * canvas-authored page had never been renderable; nothing failed anywhere, which is why it took a
     * browser to find.
     *
     * One fragment and not one statement per root: adjacent roots are the normal shape for a real page,
     * and a `return` takes one expression.
     *
     * @param  array<int, array<string, mixed>>  $doc
     */
    public static function printModule(array $doc): string
    {
        if ($doc === []) {
            // An emptied page is a legitimate state and must still produce a module that renders
            // nothing, rather than one with no default export (which reads to a client as a failure).
            return "export default function Page() {\n  return null;\n}\n";
        }

        $body = implode("\n", array_map(fn (array $n) => self::printNode($n, 3), $doc));

        return "export default function Page() {\n  return (\n    <>\n{$body}\n    </>\n  );\n}\n";
    }

    /** @param  array<string, mixed>  $node */
    private static function printNode(array $node, int $depth): string
    {
        $pad = str_repeat(self::INDENT, $depth);
        $kind = $node['kind'] ?? null;

        if ($kind === 'text') {
            return $pad.self::escapeText(trim((string) ($node['value'] ?? '')));
        }

        if ($kind === 'opaque') {
            return $pad.(string) ($node['source'] ?? '');
        }

        $isFragment = ($node['name'] ?? null) === null;
        $tag = (string) ($node['name'] ?? '');
        /** @var array<int, array<string, mixed>> $props */
        $props = (array) ($node['props'] ?? []);
        $attrs = implode('', array_map([self::class, 'printProp'], $props));
        $open = $isFragment ? '<>' : "<{$tag}{$attrs}>";
        $close = $isFragment ? '</>' : "</{$tag}>";

        /** @var array<int, array<string, mixed>> $children */
        $children = (array) ($node['children'] ?? []);

        if (count($children) === 0) {
            // A fragment always uses the paired form; a self-closing element otherwise.
            return $isFragment ? $pad.'<></>' : $pad."<{$tag}{$attrs} />";
        }

        // A leaf (text-only children) prints inline for readability + stable round-trip.
        if (self::isLeafText($children)) {
            $inner = implode('', array_map(
                fn (array $c) => self::escapeText(trim((string) ($c['value'] ?? ''))),
                $children,
            ));

            return "{$pad}{$open}{$inner}{$close}";
        }

        $kids = implode("\n", array_map(fn (array $c) => self::printNode($c, $depth + 1), $children));

        return "{$pad}{$open}\n{$kids}\n{$pad}{$close}";
    }

    /** @param  array<int, array<string, mixed>>  $children */
    private static function isLeafText(array $children): bool
    {
        if (count($children) === 0) {
            return false;
        }

        foreach ($children as $c) {
            if (($c['kind'] ?? null) !== 'text') {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $p */
    private static function printProp(array $p): string
    {
        $name = (string) ($p['name'] ?? '');
        $value = $p['value'] ?? '';

        return match ($p['kind'] ?? 'string') {
            'boolean-shorthand' => " {$name}",
            // UNESCAPED_SLASHES: matches JS's `JSON.stringify` (which never escapes `/`) — plain PHP
            // `json_encode` escapes it by default, which would otherwise diverge from the client-side
            // printer's output for any URL-bearing prop (href, src, …), the most common expression shape.
            'string' => " {$name}=".json_encode((string) $value, JSON_UNESCAPED_SLASHES),
            'number' => " {$name}={{$value}}",
            'boolean' => " {$name}={".($value ? 'true' : 'false').'}',
            // 'expression' (and any unknown kind, degrade-not-fabricate): `value` is the source text
            // with `{…}` already stripped by the lens; re-wrap it.
            default => ' '.$name.'={'.trim((string) $value).'}',
        };
    }

    /** Escape a text leaf for JSX body content (only `{`, `}`, `<`, `>` are special). */
    private static function escapeText(string $s): string
    {
        return (string) preg_replace_callback('/[{}<>]/', fn (array $m) => "{'{$m[0]}'}", $s);
    }
}
