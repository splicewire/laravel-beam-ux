<?php

namespace Splicewire\Beam\Ux\Canvas;

use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Server-side enforcement of a JsonDoc body's per-node `data-view-gate` entitlement key — the
 * companion package `@splicewire/beam-ux/canvas`'s `data-edit-gate` is enforced CLIENT-side (a
 * cosmetic seal only, since the content is already visible to an author in read mode); view-gate is
 * the REAL security boundary, so this strips a gated node's entire subtree out of the body before it
 * ever reaches an unentitled viewer's response — a client-side-only check would still ship the
 * content over the wire regardless of what the DOM hides.
 *
 * A JsonDoc is a plain array tree mirroring `@splicewire/beam-ux/blockdoc/json`'s `JsonNode` shape:
 * `{kind: 'block', props: [{name, kind, value}, ...], children: [...]}` / `{kind: 'text', value}` /
 * `{kind: 'opaque', ...}`. Not every entry body is a JsonDoc (a `theme` entry's body is a
 * `{canvas, site}` token object, not a block tree) — `filter()` detects the shape and passes any
 * non-JsonDoc body through unchanged rather than corrupting it.
 */
class ViewGateFilter
{
    public const ATTR = 'data-view-gate';

    public function __construct(private Gate $gate) {}

    /**
     * @param  array<int|string, mixed>  $body
     * @return array<int|string, mixed>
     */
    public function filter(array $body): array
    {
        if (! $this->isJsonDoc($body)) {
            return $body;
        }

        $out = [];
        foreach ($body as $node) {
            $filtered = $this->filterNode($node);
            if ($filtered !== null) {
                $out[] = $filtered;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null  null drops the node (and its whole subtree)
     */
    private function filterNode(array $node): ?array
    {
        if (($node['kind'] ?? null) !== 'block') {
            return $node;
        }

        $key = $this->gateKeyOf($node);
        if ($key !== null && ! $this->gate->allows("entitlement:{$key}")) {
            return null;
        }

        $children = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $filtered = $this->filterNode($child);
                if ($filtered !== null) {
                    $children[] = $filtered;
                }
            }
        }
        $node['children'] = $children;

        return $node;
    }

    /** @param  array<string, mixed>  $block */
    private function gateKeyOf(array $block): ?string
    {
        foreach ((array) ($block['props'] ?? []) as $prop) {
            if (is_array($prop) && ($prop['name'] ?? null) === self::ATTR) {
                $value = $prop['value'] ?? null;

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /** A JsonDoc is a list (0-indexed array) of `kind`-tagged nodes — anything else passes through. */
    private function isJsonDoc(array $body): bool
    {
        if (! array_is_list($body)) {
            return false;
        }

        foreach ($body as $node) {
            if (! is_array($node) || ! array_key_exists('kind', $node)) {
                return false;
            }
        }

        return true;
    }
}
