<?php

namespace Splicewire\Beam\Ux\Frame;

use Rushing\DataNav\InvocableNavItem;
use Rushing\DataNav\NavNode;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;

/**
 * Projects the top-level nav SEATS a package declared ({@see NavSection}, `beam.nav.sections`) into
 * the {@see InvocableNavItem} nodes a navigation is made of — the second half of a seam whose first
 * half deliberately carries no `Rushing\DataNav` type.
 *
 * ## Why the declaration lives one package down
 *
 * The packages that need to declare a seat — `laravel-beam-calendars`, `-notifications`,
 * `-workflows` — require ONLY `splicewire/laravel-beam`. They have neither beam-ux nor data-nav. So
 * `NavSection` is a plain value object in beam, and the translation to a nav node happens HERE, in
 * the one package that already depends on data-nav, frame and beam at once. A package declares in
 * vocabulary it has; this turns it into vocabulary it does not.
 *
 * ## What a seat is, and what it is not
 *
 * A seat is a section HEADER that auto-attaches the resources declaring `section:` matching its key
 * — the {@see FrameResourcesInvocable} does the attaching. The seat carries the label, icon, href
 * and gate vocabulary; it never carries children of its own. `routeName` is always `<key>.section`,
 * which is the spelling {@see RouteContextValidator} exempts from leaf binding, so a seat can never
 * be the thing that makes a manifest throw.
 *
 * ## Ordering is a preference, not a claim
 *
 * `NavSection::$order` orders the declared seats among THEMSELVES. It cannot order them against a
 * host's own hand-written sections, because a host that registers its own navigation supersedes this
 * projection wholesale — `NavRegistry` is `PickOne`/`Supersede` and host providers boot last. That
 * is the documented override seam and it is preserved by doing nothing.
 *
 * ## Every node is stamped `contributed`
 *
 * The stamp goes in the `#[Hidden]` meta bag, so it is server-only and never reaches the wire. It
 * exists so {@see FrameNavContribution} can tell a node a PACKAGE contributed from one the HOST
 * spelled out, and apply the unbound-route rule that differs between them — see the pruning
 * docblock there. Provenance is the whole reason the two can be treated differently at all.
 */
class NavSectionProjector
{
    /** The `#[Hidden]` meta key marking a node as package-contributed rather than host-spelled. */
    public const CONTRIBUTED = 'beam.nav.contributed';

    public function __construct(private NavSectionRegistry $sections) {}

    /**
     * The declared seats for one realm, in the registry's projection order.
     *
     * A realm no package targeted yields an empty list — not an error. "Which realms exist here" is
     * a host fact, and a package declaring a seat for a realm this host does not ship is a silent
     * no-op, exactly as an unmatched `RealmOverlay` is at projection time.
     *
     * @return array<int, NavNode>
     */
    public function project(string $realm): array
    {
        return array_map(
            fn (NavSection $section): NavNode => $this->seat($section),
            $this->sections->for($realm),
        );
    }

    /**
     * One declared section as a nav node.
     *
     * The `input` mirrors what a host's own section helper passes, so the collector cannot tell a
     * declared seat from a hand-written one — which is the point: the attachment rules, the sort and
     * the `viewAny` gating are identical either way.
     */
    private function seat(NavSection $section): NavNode
    {
        $node = InvocableNavItem::make(
            title: $section->label,
            invocable: FrameResourcesInvocable::NAME,
            input: [
                'section' => $section->key,
                'realm' => $section->realm,
                'static' => $section->static,
            ],
            href: $section->href,
            match: trim($section->href, '/').'*',
            icon: $section->icon,
            routeName: $section->key.'.section',
        );

        // `gate()` already drops a null and keeps an empty array, so "declared and unsatisfiable"
        // stays distinguishable from "never declared" all the way to the gate stage.
        return $node->withMeta($section->gate() + [self::CONTRIBUTED => true]);
    }
}
