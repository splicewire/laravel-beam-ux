<?php

namespace Splicewire\Beam\Ux\Containment;

use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **entry half** of ADR-0213 §7's resolution order — *registered component first, then another
 * entry's slug* — stated once, so the renderer and the doctor agree on what "then another entry"
 * means.
 *
 * A chrome name that is not a registered component is a slug, and the renderer nests the artifact of
 * the entry that carries it. It can only do that for an entry it can actually import: a live row, of
 * a type the compiler compiles (a page — {@see CompileEntryBody::compilable()}, which is also what
 * `EntryArtifactController` 404s on), holding an artifact compiled from its current body, and not
 * the entry being rendered. Anything else is a slug match that resolves to nothing on screen — the
 * case `BeamUxChromeAudit` used to accept and the renderer never implemented (beam-docs-satellite 55).
 *
 * Why it lives beside {@see ChromeResolver} rather than in it: that class resolves a NAME from a chain
 * it is handed and deliberately never queries (ADR-0209 §3). This one has to — a slug is a lookup — and
 * it runs once per axis per request, off an indexed column, never a traversal.
 *
 * Whether a name is also a *registered* component is unknowable here (the registry is a TypeScript map
 * in the host's bundle) and is not asked: the renderer prefers a registered component over an entry of
 * the same name on its own, so handing it an address it does not use costs one lookup and nothing else.
 */
class ChromeEntryResolver
{
    public function __construct(
        private CompileEntryBody $compile,
        private EntryArtifactStore $artifacts,
    ) {}

    /**
     * The entry the renderer nests for a chrome name, or null when no entry can be nested for it.
     *
     * @param  BeamUxEntry|null  $for  the entry being rendered — an entry never frames itself
     */
    public function resolve(string $name, ?BeamUxEntry $for = null): ?BeamUxEntry
    {
        $entry = BeamUxEntry::query()->where('slug', $name)->first();

        if ($entry === null || $this->because($entry, $for) !== null) {
            return null;
        }

        return $entry;
    }

    /**
     * Why a name that IS some entry's slug cannot be nested — null when it can, and null when no entry
     * (live or deleted) carries the slug at all, which is a different finding (the name is unresolved).
     *
     * @param  BeamUxEntry|null  $for  the entry being rendered
     */
    public function unnestable(string $name, ?BeamUxEntry $for = null): ?string
    {
        $entry = BeamUxEntry::query()->withTrashed()->where('slug', $name)
            ->orderByRaw('deleted_at is not null')
            ->first();

        return $entry === null ? null : $this->because($entry, $for);
    }

    private function because(BeamUxEntry $entry, ?BeamUxEntry $for): ?string
    {
        if ($entry->trashed()) {
            return 'is deleted';
        }

        if ($for !== null && (string) $entry->getKey() === (string) $for->getKey()) {
            return 'names itself';
        }

        if (! $this->compile->compilable($entry)) {
            $type = $entry->type?->value ?? 'untyped';
            $format = $entry->format?->value ?? 'no format';

            return "is a {$type} ({$format}) — only a page's body compiles to an artifact the renderer can nest";
        }

        if (! $this->artifacts->has($entry)) {
            return 'has no artifact compiled from its current body — run `splicewire:beam:ux:compile`';
        }

        return null;
    }
}
