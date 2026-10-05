<?php

namespace Splicewire\Beam\Ux\Ia;

/**
 * One IA invariant, judged node by node over a realm's PROJECTED nav (ux-walkthrough SPEC M5).
 *
 * This is the slot {@see IaInvariants} runs: I1 and I4 ship with UX-06, and I2 (audience, UX-08) and I3 (task
 * sections, UX-09) plug in here with the fields they read, through {@see IaInvariants::with()}.
 */
interface NavInvariant
{
    /** The invariant's id as the SPEC names it (`I1`, `I4`, …), the first word of every violation id. */
    public function id(): string;

    /**
     * Why this node breaks the invariant in this realm's rail, or null when it holds.
     *
     * @param  array<string, mixed>  $node  one projected nav node (`NavNode::toArray()`)
     * @param  list<array<string, mixed>>  $ancestors  its ancestors, outermost first
     * @param  list<string>  $crossings  realms this rail may link into anyway (a listed exception, never a default)
     */
    public function violation(string $realm, array $node, array $ancestors, array $crossings): ?string;
}
