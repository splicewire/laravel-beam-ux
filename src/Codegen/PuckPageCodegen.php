<?php

namespace Splicewire\Beam\Ux\Codegen;

/**
 * The **Puck-Data → TSX codegen** (ADR-0164) — the outbound projection for a `page` whose edit-truth is
 * a Puck `Data` document ({root, content, zones}). On Publish the {@see \Splicewire\Beam\Ux\Storage\PlacedDiskMirror}
 * runs this to materialize a clean, git-trackable **composed-JSX `.tsx`** React component: generated
 * OUTPUT, never read back (the particle body's Puck Data stays the source-of-record the editor round-trips).
 *
 * This is deliberately DISTINCT from {@see \Splicewire\Beam\Ux\Codec\TsxBodyCodec}, which decodes a
 * **hand-authored** tsx `component`'s source — same `.tsx` extension, opposite direction (a `component`
 * body IS its source text; a `page` body is structural Puck Data compiled to source here). The two must
 * never be conflated: a page is codegen'd (safe to overwrite), a component is authored (never clobbered).
 *
 * **Write-safety:** every output opens with {@see MARKER}. The mirror only ever overwrites a file it can
 * recognize as generated (via {@see isGenerated()}), so codegen can never stomp a hand-authored source
 * file even if a placement path collided.
 *
 * The block vocabulary is the HOST's (satellite-local, e.g. audiostud's `resources/js/puck/config.tsx`);
 * the generated file imports it by NAME from the configured `blocks_module`, so this codegen stays
 * generic over any host's Puck blocks — it never hardcodes a block set.
 */
class PuckPageCodegen
{
    /** The provenance marker that opens every generated file — the write-safety key the mirror gates on. */
    public const MARKER = '@generated beam-ux puck-codegen';

    public function __construct(private string $blocksModule = '@/puck/blocks') {}

    /** Does this text look like codegen output (carries the marker)? Gates the mirror's overwrite. */
    public function isGenerated(string $text): bool
    {
        return str_contains($text, self::MARKER);
    }

    /**
     * Compile a Puck `Data` body to a composed-JSX `.tsx` React component.
     *
     * @param  array<string, mixed>  $data  the Puck Data ({root, content, zones})
     * @param  string  $slug  the entry slug — drives the component name
     */
    public function generate(array $data, string $slug): string
    {
        /** @var list<array<string, mixed>> $content */
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        /** @var array<string, list<array<string, mixed>>> $zones */
        $zones = is_array($data['zones'] ?? null) ? $data['zones'] : [];

        $blocks = $this->collectBlockTypes($content, $zones);
        $import = $blocks === []
            ? ''
            : 'import { '.implode(', ', $blocks)." } from '".$this->blocksModule."';\n\n";

        $body = $this->renderNodes($content, $zones, 3);
        $component = $this->componentName($slug);

        $header = '// '.self::MARKER." — DO NOT EDIT.\n"
            ."// Edit via the Puck page editor; this file is regenerated on Publish.\n";

        return $header
            .$import
            ."export default function {$component}() {\n"
            ."  return (\n"
            ."    <>\n"
            .($body === '' ? '' : $body."\n")
            ."    </>\n"
            ."  );\n"
            ."}\n";
    }

    /** The unique, sorted set of block type names referenced anywhere in the tree (for the import line). */
    private function collectBlockTypes(array $content, array $zones): array
    {
        $types = [];

        $walk = function (array $nodes) use (&$walk, &$types, $zones): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $type = $node['type'] ?? null;
                if (is_string($type) && $type !== '') {
                    $types[$type] = true;
                }
                foreach ($this->childSlotsFor($node, $zones) as $children) {
                    $walk($children);
                }
            }
        };

        $walk($content);
        ksort($types);

        return array_keys($types);
    }

    /** A node's props map (`{}` when absent or malformed). */
    private function propsOf(array $node): array
    {
        return is_array($node['props'] ?? null) ? $node['props'] : [];
    }

    /** Render a list of Puck nodes as indented JSX. */
    private function renderNodes(array $nodes, array $zones, int $indent): string
    {
        $lines = [];
        foreach ($nodes as $node) {
            $lines[] = $this->renderNode($node, $zones, $indent);
        }

        return implode("\n", array_filter($lines, fn ($l) => $l !== ''));
    }

    /** Render one node — self-closing when it has no slot children, open/nested when it does. */
    private function renderNode(array $node, array $zones, int $indent): string
    {
        $type = $node['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return '';
        }

        $pad = str_repeat('  ', $indent);
        $props = $this->propsOf($node);

        $attrs = $this->renderAttrs($props);
        $attrStr = $attrs === '' ? '' : ' '.$attrs;

        // Slot children compose as this element's JSX children — from EITHER a Puck 0.20 inline slot prop
        // (a prop whose value is a list of nodes) OR the older `zones` keyed `{id}-{slot}`. A single default
        // slot is assumed (the vocabulary's `Section.content` → the component's `children`).
        $childNodes = [];
        foreach ($this->childSlotsFor($node, $zones) as $children) {
            foreach ($children as $child) {
                $childNodes[] = $child;
            }
        }

        if ($childNodes === []) {
            return "{$pad}<{$type}{$attrStr} />";
        }

        $inner = $this->renderNodes($childNodes, $zones, $indent + 1);

        return "{$pad}<{$type}{$attrStr}>\n{$inner}\n{$pad}</{$type}>";
    }

    /**
     * The slot children of a node — a list of node-lists (one per slot). Gathers BOTH:
     *   - Puck 0.20 **inline** slots: a prop whose value is a list of node-shaped arrays; and
     *   - the older **zones** form: `{id}-{slotName}` entries in the top-level `zones` map.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function childSlotsFor(array $node, array $zones): array
    {
        $slots = [];

        $props = $this->propsOf($node);
        foreach ($props as $key => $value) {
            if ($key !== 'id' && $this->isNodeList($value)) {
                $slots[] = $value;
            }
        }

        $id = is_string($props['id'] ?? null) ? $props['id'] : null;
        if ($id !== null) {
            foreach ($zones as $key => $children) {
                if (is_string($key) && str_starts_with($key, $id.'-') && $this->isNodeList($children)) {
                    $slots[] = $children;
                }
            }
        }

        return $slots;
    }

    /** Is this value a slot payload — a (possibly empty) list of Puck node arrays ({type, props})? */
    private function isNodeList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_array($item) || ! isset($item['type'])) {
                return false;
            }
        }

        return true;
    }

    /** Serialize a node's props into JSX attributes (dropping Puck's structural `id`; slots are children). */
    private function renderAttrs(array $props): string
    {
        $parts = [];
        foreach ($props as $key => $value) {
            if ($key === 'id' || ! is_string($key)) {
                continue;
            }
            // A null prop carries no value — drop it rather than emit an empty `key=""`.
            if ($value === null) {
                continue;
            }
            // A node-list prop is a slot — rendered as JSX children, never an attribute.
            if ($this->isNodeList($value)) {
                continue;
            }
            // Any other array/object prop rides a JSON expression literal (a config-shaped prop).
            if (is_array($value)) {
                $parts[] = $key.'={'.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'}';

                continue;
            }
            $parts[] = $key.'='.$this->jsxAttrValue($value);
        }

        return implode(' ', $parts);
    }

    /** A single JSX attribute value — quoted for simple strings, a template literal otherwise. */
    private function jsxAttrValue(mixed $value): string
    {
        if (is_bool($value)) {
            return '{'.($value ? 'true' : 'false').'}';
        }
        if (is_int($value) || is_float($value)) {
            return '{'.$value.'}';
        }

        $str = (string) $value;

        // A plain single-line string with no double-quote rides a normal quoted attribute; anything with a
        // newline or a `"` (which would break the attribute) rides an escaped template literal instead.
        if (! str_contains($str, "\n") && ! str_contains($str, '"')) {
            return '"'.$str.'"';
        }

        $escaped = str_replace(['\\', '`', '${'], ['\\\\', '\\`', '\\${'], $str);

        return '{`'.$escaped.'`}';
    }

    /** `library-lyrics` → `LibraryLyricsPage`. */
    private function componentName(string $slug): string
    {
        $pascal = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $slug)));

        return ($pascal === '' ? 'Page' : $pascal).'Page';
    }
}
