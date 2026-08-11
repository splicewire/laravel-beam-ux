<?php

namespace Splicewire\Beam\Ux\Lifecycle;

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * One-click clone (ticket 06): copies the source entry's full authoring envelope + body into a NEW
 * row/particle pair, auto-suffixing the slug on collision within the same `namespace`. New mechanism
 * — nothing like it exists in `ParticleResource`/Frame today, so this is beam-ux's own, not a Frame
 * seam.
 *
 * `parent_id` IS copied (a duplicate stays a sibling of its source — the natural default); trigger
 * (a), "create new," is ticket 05's `creatable` flag, entirely separate from this.
 *
 * `segment` is deliberately NOT copied (left null). `Splicewire\Beam\Ux\Containment\UrlResolver`
 * composes the PUBLIC URL from `segment` down the `parent_id` chain — `slug` never enters URL
 * resolution — so copying `segment` verbatim alongside the copied `parent_id` would land the
 * duplicate on the exact same resolved URL as its source (two independently-editable rows shadowing
 * one route, no DB constraint catches it). A null segment is a real, safe state (a structural
 * pass-through node inheriting its parent's path, per `UrlResolver`'s own grammar) — the author places
 * the duplicate explicitly before it's meant to route anywhere new.
 */
class EntryDuplicator
{
    public function __construct(private ParticleWriter $writer) {}

    public function duplicate(BeamUxEntry $source): BeamUxEntry
    {
        $particle = $this->writer->write(new BeamParticle, $source->particle?->payload ?? []);

        return BeamUxEntry::create([
            'slug' => $this->uniqueSlug($source),
            'title' => $source->title,
            'schema_ref' => $source->schema_ref,
            'schema_is_draft' => $source->schema_is_draft,
            'facade_ref' => $source->facade_ref,
            'type' => $source->type,
            'format' => $source->format,
            'body_style' => $source->body_style,
            'namespace' => $source->namespace,
            'placement_ref' => $source->placement_ref,
            'driver_ref' => $source->driver_ref,
            'residency_mode' => $source->residency_mode,
            'particle_id' => $particle->id,
            'realm' => $source->realm,
            'realms' => $source->realms,
            'parent_id' => $source->parent_id,
            'segment' => null,
        ]);
    }

    /**
     * `{slug}-copy`, incrementing (`{slug}-copy-2`, `{slug}-copy-3`, …) on collision — checked against
     * LIVE rows only (not `withTrashed()`): the unique index is partial (`WHERE deleted_at IS NULL`
     * on drivers that support one, ticket 06), so a soft-deleted entry's slug is free to reuse.
     */
    private function uniqueSlug(BeamUxEntry $source): string
    {
        $base = "{$source->slug}-copy";
        $slug = $base;
        $suffix = 1;

        while (BeamUxEntry::query()->where('namespace', $source->namespace)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
