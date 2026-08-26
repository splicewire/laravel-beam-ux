<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Resolves an entry's **chrome** — its `layout` and `template` — from the root-first containment chain
 * the renderer's reverse walk already holds (ADR-0213 §4).
 *
 * Each axis is inherited from the **nearest ancestor that declares one**, with a per-entry override.
 * `/docs` declares `DocsLayout` once and every descendant gets it; `/docs/api` overrides only its
 * `template` to `SpreadTemplate` and keeps the inherited layout. That is the whole model, and it is
 * deliberately the same shape as ADR-0212's rights: one named column per inherited aspect, composed
 * over the chain a caller already holds.
 *
 * **No second traversal** (ADR-0209 §3). This class never queries and never touches `parent` — hand it
 * the chain, get the answer. {@see PublicEntryGate::chainFor()} produced that chain to run the access
 * conjunction over; resolving chrome from anything else would mean walking the tree twice per request
 * to answer two questions about the same rows.
 *
 * **The two axes are independent.** A chain declaring a layout at the root and a template at the leaf
 * resolves both — the nearest declarer is found per axis, not per row. Conflating them (taking both
 * from the nearest row declaring *either*) would make declaring a template silently drop an inherited
 * layout, which is the failure this shape exists to avoid.
 *
 * What a resolved NAME means is the client's business (ADR-0213 §7): registered component first, then
 * another entry's slug. Nothing here resolves a name to a component, because a layout may be
 * package-shipped code with no row at all — and a PHP-side lookup that fails to find a row would have
 * to report "unknown" for exactly the ones that are working. {@see \Splicewire\Beam\Ux\Doctor\BeamUxChromeAudit}
 * is where a name is checked, deliberately as a doctor finding rather than a render-time abort.
 */
class ChromeResolver
{
    /**
     * The resolved `{layout, template}` for the chain's target (its last element).
     *
     * @param  array<int, BeamUxEntry>  $chain  root-first, target last
     * @return array{layout: ?string, template: ?string}
     */
    public function resolve(array $chain): array
    {
        return [
            'layout' => $this->nearest($chain, 'layout'),
            'template' => $this->nearest($chain, 'template'),
        ];
    }

    /**
     * The value of `$column` on the nearest declaring row, walking from the target BACK towards the
     * root — so the target's own declaration wins and the root's is the last resort.
     *
     * An empty string is treated as no declaration. Frontmatter and form posts both produce `''` for a
     * key someone left blank, and "inherit" is the honest reading of a blank field; there is no
     * declared-but-empty *denial* semantic here the way `access: []` has one, because chrome has
     * nothing to deny.
     *
     * @param  array<int, BeamUxEntry>  $chain
     */
    private function nearest(array $chain, string $column): ?string
    {
        foreach (array_reverse($chain) as $entry) {
            $value = $entry->getAttribute($column);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
