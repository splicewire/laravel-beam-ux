<?php

namespace Splicewire\Beam\Ux\Http;

use Closure;
use LogicException;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * Page props a package contributes for the layout it owns (docs-walkthrough DOCS-12, DM4). A page whose resolved layout
 * is that layout carries them beside the core props; every other page carries nothing new, so a host with no contributor
 * sees today's payload exactly.
 *
 * A contributor receives the entry, the containment chain the gate already walked (root first) and the actor, and
 * returns `prop => value`. It may not replace a core prop: those are the renderer's contract with every shell.
 */
final class EntryPageProps
{
    /** The props {@see Controllers\PublicEntryController} owns. */
    public const CORE = ['entry', 'artifact', 'chrome', 'nav'];

    /** @var array<string, list<Closure(BeamUxEntry, list<BeamUxEntry>, mixed): array<string, mixed>>> */
    private array $contributors = [];

    /** @param  Closure(BeamUxEntry, list<BeamUxEntry>, mixed): array<string, mixed>  $props */
    public function contribute(string $layout, Closure $props): void
    {
        $this->contributors[$layout][] = $props;
    }

    /**
     * @param  list<BeamUxEntry>  $chain
     * @return array<string, mixed>
     */
    public function for(?string $layout, BeamUxEntry $entry, array $chain, mixed $actor): array
    {
        $props = [];

        foreach ($this->contributors[(string) $layout] ?? [] as $contributor) {
            $contributed = $contributor($entry, $chain, $actor);
            $clash = array_intersect(array_keys($contributed), self::CORE);
            if ($clash !== []) {
                throw new LogicException("A '{$layout}' contributor may not replace the core page prop(s): ".implode(', ', $clash).'.');
            }
            $props = [...$props, ...$contributed];
        }

        return $props;
    }
}
