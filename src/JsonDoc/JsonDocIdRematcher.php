<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Illuminate\Support\Str;

/**
 * ADR-0228 d3/d9 — reattaches the Working Head's stable node ids onto a freshly-parsed, id-less JsonDoc
 * (the TSX Lens's output), so a block keeps its UUIDv7 id across a source round trip. That stability is
 * what lets a save's strictness narrow to only the nodes it truly added or changed ({@see BodyNodeDiff}).
 *
 * Modeled on the schemastud `NodeIdRematcher` PRECEDENT: a content match first, then a `similar_text`
 * similarity fallback for a node whose text was edited, and a node with no match mints a fresh UUIDv7
 * (ADR-0228 d3). It is beam-native, not that precedent's code: it reads the beam JsonDoc shape
 * (`kind`/`name`/`children`/`id`), never ProseMirror's `type`/`attrs`/`content`, so beam stays uncoupled
 * from ProseMirror. Only block nodes carry ids; text and opaque nodes are returned untouched. Each
 * Working Head id is reattached at most once.
 */
final class JsonDocIdRematcher
{
    public function __construct(private float $threshold = 0.6) {}

    /**
     * @param  array<int, array<string, mixed>>  $workingHead  the current stored JsonDoc (carries ids)
     * @param  array<int, array<string, mixed>>  $parsed  the lens output (id-less)
     * @return array<int, array<string, mixed>> $parsed with ids reattached or freshly minted
     */
    public function rematch(array $workingHead, array $parsed): array
    {
        $bySignature = [];
        $pool = [];
        $this->index($workingHead, $bySignature, $pool);

        $used = [];

        return $this->reattach($parsed, $bySignature, $pool, $used);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, list<string>>  $bySignature  exact content signature => surviving ids
     * @param  list<array{id: string, name: string|null, text: string}>  $pool  candidates for similarity
     */
    private function index(array $nodes, array &$bySignature, array &$pool): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['kind'] ?? null) !== 'block') {
                continue;
            }
            $id = $node['id'] ?? null;
            if (is_string($id)) {
                $bySignature[$this->signature($node)][] = $id;
                $pool[] = ['id' => $id, 'name' => $node['name'] ?? null, 'text' => $this->plainText($node)];
            }
            $this->index($node['children'] ?? [], $bySignature, $pool);
            $this->index($node['repeat']['empty'] ?? [], $bySignature, $pool);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<string, list<string>>  $bySignature
     * @param  list<array{id: string, name: string|null, text: string}>  $pool
     * @param  array<string, true>  $used
     * @return array<int, array<string, mixed>>
     */
    private function reattach(array $nodes, array &$bySignature, array &$pool, array &$used): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['kind'] ?? null) !== 'block') {
                $out[] = $node; // text and opaque nodes carry no id

                continue;
            }

            $id = $this->exactId($node, $bySignature, $used)
                ?? $this->similarId($node, $pool, $used)
                ?? (string) Str::uuid7();
            $used[$id] = true;

            $node['id'] = $id;
            $node['children'] = $this->reattach($node['children'] ?? [], $bySignature, $pool, $used);
            if (isset($node['repeat']['empty'])) {
                $node['repeat']['empty'] = $this->reattach($node['repeat']['empty'], $bySignature, $pool, $used);
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $bySignature
     * @param  array<string, true>  $used
     */
    private function exactId(array $node, array &$bySignature, array $used): ?string
    {
        $sig = $this->signature($node);
        foreach ($bySignature[$sig] ?? [] as $k => $id) {
            if (! isset($used[$id])) {
                unset($bySignature[$sig][$k]);

                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: string, name: string|null, text: string}>  $pool
     * @param  array<string, true>  $used
     */
    private function similarId(array $node, array $pool, array $used): ?string
    {
        $text = $this->plainText($node);
        $name = $node['name'] ?? null;
        $best = null;
        $bestPct = $this->threshold * 100;

        foreach ($pool as $candidate) {
            if (isset($used[$candidate['id']]) || $candidate['name'] !== $name) {
                continue;
            }
            similar_text($text, $candidate['text'], $pct);
            if ($pct >= $bestPct) {
                $bestPct = $pct;
                $best = $candidate['id'];
            }
        }

        return $best;
    }

    private function signature(array $node): string
    {
        // Content identity: the node's own shape and its text, but NOT its id or its children's ids
        // (a child is matched on its own). Same signature => the content is unchanged.
        return json_encode([
            $node['name'] ?? null,
            $node['kind'] ?? null,
            $node['isComponent'] ?? false,
            $node['dynamic'] ?? false,
            $node['props'] ?? [],
            $this->plainText($node),
        ], JSON_THROW_ON_ERROR);
    }

    private function plainText(array $node): string
    {
        $text = '';
        foreach ($node['children'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            $kind = $child['kind'] ?? null;
            if ($kind === 'text') {
                $text .= (string) ($child['value'] ?? '');
            } elseif ($kind === 'block') {
                $text .= $this->plainText($child);
            }
        }

        return $text;
    }
}
