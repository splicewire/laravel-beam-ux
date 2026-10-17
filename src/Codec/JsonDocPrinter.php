<?php

namespace Splicewire\Beam\Ux\Codec;

use Splicewire\Beam\Ux\JsonDoc\JsonBlockNode;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Beam\Ux\JsonDoc\JsonOpaqueNode;
use Splicewire\Beam\Ux\JsonDoc\JsonProp;
use Splicewire\Beam\Ux\JsonDoc\JsonTextNode;
use Splicewire\Beam\Ux\JsonDoc\PropKind;

/**
 * A **PHP port** of `@splicewire/beam-ux/blockdoc/json.ts`'s `jsonToTsx()` — the pure, Babel-free
 * `JsonNode[]` → TSX-source printer the canvas editor's own client-side "Source" toggle already uses.
 * Kept a behavioral mirror (same leaf-inlining rule, same prop-kind switch, same text escaping) so a
 * canvas-authored body decodes to the SAME source whether printed client-side or here — this is the
 * server-side half: {@see TsxBodyCodec::decode()} calls it for a blockdoc-shaped body (`PlacedDiskMirror`'s
 * materialize-on-save path runs entirely server-side, so it can't reach the JS printer directly).
 *
 * ADR-0228 d2: the printer no longer hand-mirrors the raw array keys. It HYDRATES the particle payload
 * through the declared {@see JsonNode} morph union and reads the typed tree — `$block->name`,
 * `$prop->kind`, `$text->value` — so the shape it consumes is the same declared one the TypeScript and
 * JSON Schema are generated from, and a drift between the two can no longer pass silently. The output is
 * unchanged, byte for byte (its golden test pins that).
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
     * printer, `jsonToTsx()` (`blockdoc/json.ts`).
     *
     * @param  array<int, array<string, mixed>>  $doc
     */
    public static function print(array $doc, int $depth = 0): string
    {
        return implode("\n", array_map(
            fn (JsonNode $n) => self::printNode($n, $depth).';',
            self::hydrate($doc),
        ));
    }

    /**
     * Print a JSON document as a **compilable ES module** — a default-exported component returning the
     * whole tree in one fragment.
     *
     * {@see print()} deliberately emits bare JSX STATEMENTS, because its consumer is the disk mirror and
     * `@splicewire/beam-ux/blockdoc`'s `parse()` has to read that file back. The COMPILER's consumer is
     * different and irreconcilable with it: `EntryArtifactController` serves a module that `<EntryBody>`
     * imports and calls, reading `default` off what it returns. Bare statements are a valid file and an
     * empty module — esbuild compiles them, nothing is exported, and the reader gets a module with no
     * `default` (G2-BEAM-AUTHOR-ENTRY, beam.test 2026-09-11).
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

        $nodes = self::hydrate($doc);

        $body = implode("\n", array_map(fn (JsonNode $n) => self::printNode($n, 3, module: true), $nodes));
        $islands = self::componentNamesOf($nodes);

        // Island names are BARE IDENTIFIERS in the printed JSX, and an artifact imports nothing — so
        // they have to come from somewhere. `<EntryBody>` already hands a compiled body its host
        // registry on a `components` prop (the compiler runs without `providerImportSource`, ADR-0209),
        // and this is the line that connects the two: the canvas resolves `<DemoHero>` through its
        // `CanvasConfig` registry, and the artifact resolves the SAME name through the SAME map
        // (beam.test 2026-09-11: without it a saved page threw `DemoHero is not defined`).
        $destructure = $islands === []
            ? ''
            : '  const { '.implode(', ', $islands)."} = components;\n";
        $signature = $islands === [] ? 'Page()' : 'Page({ components = {} })';

        return "export default function {$signature} {\n{$destructure}  return (\n    <>\n{$body}\n    </>\n  );\n}\n";
    }

    /**
     * Every distinct COMPONENT island name in the document, in first-seen order — the names the printed
     * JSX references as bare identifiers and {@see printModule()} destructures from `components`.
     *
     * `isComponent` is the lens's own answer (a PascalCase tag it could not decompose), so this asks the
     * document rather than re-deciding by casing.
     *
     * @param  array<int, array<string, mixed>>  $doc
     * @return list<string>
     */
    public static function componentNames(array $doc): array
    {
        return self::componentNamesOf(self::hydrate($doc));
    }

    /**
     * Hydrate the raw particle payload (decoded JSON) through the declared {@see JsonNode} union. Each
     * root resolves to its arm by `kind`, and `props`/`children` hydrate recursively as `JsonProp[]` /
     * `JsonNode[]`, so the rest of the printer reads a typed tree, not array keys.
     *
     * @param  array<int, array<string, mixed>>  $doc
     * @return list<JsonNode>
     */
    private static function hydrate(array $doc): array
    {
        return array_map(
            fn (array $node): JsonNode => JsonNode::from($node),
            array_values($doc),
        );
    }

    /**
     * @param  list<JsonNode>  $nodes
     * @return list<string>
     */
    private static function componentNamesOf(array $nodes): array
    {
        $found = [];

        $walk = function (array $nodes) use (&$walk, &$found): void {
            foreach ($nodes as $node) {
                if (! $node instanceof JsonBlockNode) {
                    continue;
                }

                if ($node->isComponent && is_string($node->name) && $node->name !== '') {
                    $found[$node->name] = true;
                }

                $walk($node->children);
            }
        };

        $walk($nodes);

        return array_keys($found);
    }

    private static function printNode(JsonNode $node, int $depth, bool $module = false): string
    {
        $pad = str_repeat(self::INDENT, $depth);

        if ($node instanceof JsonTextNode) {
            return $pad.self::escapeText(trim($node->value));
        }

        if ($node instanceof JsonOpaqueNode) {
            return $pad.$node->source;
        }

        /** @var JsonBlockNode $node */
        $isFragment = $node->name === null;
        $tag = (string) ($node->name ?? '');
        $attrs = implode('', array_map(fn (JsonProp $prop) => self::printProp($prop, $module), $node->props));
        $open = $isFragment ? '<>' : "<{$tag}{$attrs}>";
        $close = $isFragment ? '</>' : "</{$tag}>";

        $children = $node->children;

        if (count($children) === 0) {
            // A fragment always uses the paired form; a self-closing element otherwise.
            return $isFragment ? $pad.'<></>' : $pad."<{$tag}{$attrs} />";
        }

        // A leaf (text-only children) prints inline for readability + stable round-trip.
        if (self::isLeafText($children)) {
            $inner = implode('', array_map(
                fn (JsonNode $c) => self::escapeText(trim($c instanceof JsonTextNode ? $c->value : '')),
                $children,
            ));

            return "{$pad}{$open}{$inner}{$close}";
        }

        $kids = implode("\n", array_map(fn (JsonNode $c) => self::printNode($c, $depth + 1, $module), $children));

        return "{$pad}{$open}\n{$kids}\n{$pad}{$close}";
    }

    /**
     * @param  list<JsonNode>  $children
     */
    private static function isLeafText(array $children): bool
    {
        if (count($children) === 0) {
            return false;
        }

        foreach ($children as $c) {
            if (! $c instanceof JsonTextNode) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  bool  $module  printing for {@see printModule()} (an executable artifact) rather than for
     *                        the disk mirror. The only difference is `style`, and it is not cosmetic:
     *                        JSX source may carry `style="max-width:900px"` and React REFUSES a string
     *                        at runtime (minified error #62), killing the page. The canvas has always
     *                        parsed it, so an artifact that does not is the lens that disagrees
     *                        (beam.test 2026-09-11). The mirror keeps the string: that file is read back
     *                        by `blockdoc`'s `parse()`, which expects the authored attribute.
     */
    private static function printProp(JsonProp $p, bool $module = false): string
    {
        $name = $p->name;
        $value = $p->value;

        if ($module && $name === 'style' && $p->kind === PropKind::StringValue) {
            $parsed = self::parseStyle((string) $value);

            return $parsed === [] ? '' : ' style={'.json_encode($parsed, JSON_UNESCAPED_SLASHES).'}';
        }

        return match ($p->kind) {
            PropKind::BooleanShorthand => " {$name}",
            // UNESCAPED_SLASHES: matches JS's `JSON.stringify` (which never escapes `/`) — plain PHP
            // `json_encode` escapes it by default, which would otherwise diverge from the client-side
            // printer's output for any URL-bearing prop (href, src, …).
            PropKind::StringValue => " {$name}=".json_encode((string) $value, JSON_UNESCAPED_SLASHES),
            // JsonProp admits only a finite int|float here; json_encode prints it as JS's JSON.stringify does.
            PropKind::NumberValue => " {$name}={".json_encode($value).'}',
            PropKind::BooleanValue => " {$name}={".($value ? 'true' : 'false').'}',
            // Expression (and, defensively, any later-added kind): `value` is the source text with `{…}`
            // already stripped by the lens; re-wrap it.
            default => ' '.$name.'={'.trim((string) $value).'}',
        };
    }

    /**
     * A CSS declaration string to React's style object — the PHP port of
     * `@splicewire/beam-ux/blockdoc`'s `parseStyle()`, including its kebab→camel property rename, which
     * is what React actually requires (it ignores `font-size` and warns).
     *
     * @return array<string, string>
     */
    private static function parseStyle(string $css): array
    {
        $out = [];

        foreach (explode(';', $css) as $declaration) {
            $at = strpos($declaration, ':');

            if ($at === false) {
                continue;
            }

            $property = (string) preg_replace_callback(
                '/-([a-z])/',
                fn (array $m) => strtoupper($m[1]),
                trim(substr($declaration, 0, $at)),
            );

            if ($property !== '') {
                $out[$property] = trim(substr($declaration, $at + 1));
            }
        }

        return $out;
    }

    /** Escape a text leaf for JSX body content (only `{`, `}`, `<`, `>` are special). */
    private static function escapeText(string $s): string
    {
        return (string) preg_replace_callback('/[{}<>]/', fn (array $m) => "{'{$m[0]}'}", $s);
    }
}
