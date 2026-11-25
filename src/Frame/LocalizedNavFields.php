<?php

namespace Splicewire\Beam\Ux\Frame;

use Splicewire\Beam\Localization\PseudoLocale;

/**
 * The frame manifest's `nav` contract for text (frame-foundation 82): which fields reach the SPA in the request's
 * language. Each one is resolved at emit by {@see FrameNavContribution}, through the same `navRouteTitle` call, at any depth:
 *
 *  - every node's `title`, its `children[]`' titles, and theirs (`*.title`);
 *  - an invocable node's `input.static[].title` and `input.groups[].label`, the author's words its children were made from.
 *
 * Nothing else in `nav` is text (hrefs, matches, icons and route names are identity). {@see unmarked()} is the check a host
 * runs over its own manifest under the pseudo-locale: it names every field above that came through unmarked, so a raw
 * string sent through a child link is a failing test, not a silent English word in a translated rail.
 */
final class LocalizedNavFields
{
    /**
     * The path of every localized field in a serialized `nav` payload (its `items`, or the whole `nav` array) that is NOT
     * marked by the pseudo-locale. Empty means every field was localized. An empty string is not text and is skipped.
     *
     * @param  array<array-key, mixed>  $nav
     * @return list<string>
     */
    public static function unmarked(array $nav): array
    {
        $items = is_array($nav['items'] ?? null) ? $nav['items'] : $nav;

        return self::walk($items, 'items');
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return list<string>
     */
    private static function walk(array $nodes, string $at): array
    {
        $out = [];
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $here = $at.'.'.$i;
            self::check($node['title'] ?? null, $here.'.title', $out);
            foreach ((array) ($node['input']['static'] ?? []) as $j => $row) {
                self::check(is_array($row) ? ($row['title'] ?? null) : null, $here.'.input.static.'.$j.'.title', $out);
            }
            foreach ((array) ($node['input']['groups'] ?? []) as $j => $group) {
                self::check(is_array($group) ? ($group['label'] ?? null) : null, $here.'.input.groups.'.$j.'.label', $out);
            }
            if (is_array($node['children'] ?? null)) {
                array_push($out, ...self::walk($node['children'], $here.'.children'));
            }
        }

        return $out;
    }

    /** @param  list<string>  $out */
    private static function check(mixed $text, string $path, array &$out): void
    {
        if (is_string($text) && $text !== '' && ! PseudoLocale::isMarked($text)) {
            $out[] = $path.' = '.$text;
        }
    }
}
