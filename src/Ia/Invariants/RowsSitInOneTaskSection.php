<?php

namespace Splicewire\Beam\Ux\Ia\Invariants;

use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Ia\NavInvariant;

/**
 * **I3** (IA-10, UX-09): every operator-realm row sits under exactly one declared task section.
 *
 * A row is a node with an href and no children. It holds when exactly one of its ancestors is a seat the realm's
 * {@see NavSectionRegistry} declares (`{key}.section`), product or developer: the Developer zone's own node
 * (`developer.section`) is the projector's container, not a declared seat, so a developer row counts its `ops`-like seat
 * once. The realm's dashboard leaf leads the rail on its own and is the one row allowed at the top level.
 *
 * Realm membership authorizes and never seats (IA-10), so a row under no declared section (the top level, or a host's
 * hand-built section such as the flagship's former Platform) breaks it, and so does a row under two.
 *
 * Only the operator realm is judged: IA-10 names the operator rail's sections, and the tenant rail keeps its own.
 */
class RowsSitInOneTaskSection implements NavInvariant
{
    public const REALM = 'operator';

    public function __construct(protected NavSectionRegistry $sections) {}

    public function id(): string
    {
        return 'I3';
    }

    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string
    {
        if ($realm !== self::REALM || ($node['href'] ?? null) === null || ($node['children'] ?? []) !== []) {
            return null;
        }

        if ($ancestors === [] && ($node['routeName'] ?? null) === RealmDashboard::routeNameFor($realm)) {
            return null;
        }

        $declared = array_map(fn ($section): string => $section->key.'.section', $this->sections->for($realm));
        $seats = count(array_filter($ancestors, fn (array $a): bool => in_array($a['routeName'] ?? null, $declared, true)));

        return match ($seats) {
            1 => null,
            0 => 'an operator row under no declared task section',
            default => "an operator row under {$seats} declared task sections",
        };
    }
}
