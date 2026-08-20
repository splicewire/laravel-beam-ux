<?php

namespace Splicewire\Beam\Ux\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Splicewire\Beam\Taxonomy\Models\Concerns\HasTags;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The **classification facet** surface for a {@see BeamUxEntry} (charter S7,
 * ADR-0165). Attaches the sibling `laravel-beam-taxonomy` `BeamSilo` (hierarchical) + `BeamTag` (flat)
 * to the entry as **OPTIONAL polymorphic** relations — null for fragments, filled for content.
 *
 * **Classification, NOT the spine.** These facets drive filtering / related / secondary-nav, and a
 * silo's authority/visibility MAY gate (see `Splicewire\Beam\Ux\Sitemap\SiloVisibilityEntitlementGate`),
 * but they NEVER determine the canonical URL — that is the containment tree (S3, ADR-0165 §2). The two
 * are deliberately orthogonal: an entry can carry many silos/tags while its route is composed purely from
 * `realm` + `realms` + `parent_id` + `segment`.
 *
 * **The morphs (pivot-based — no new `beam_ux_entries` column):**
 *  - `tags()` — spatie taggable morph (`taggables` pivot), from beam-taxonomy's {@see HasTags} concern. The
 *    concrete tag model is host-bound via `config('beam.taxonomy.models.tag')`.
 *  - `silos()` — a `siloable` morph (`siloables` pivot). beam-taxonomy ships no `HasSilos` concern (that
 *    trait lives UP in tower over the compliance-silo estate), so beam-ux declares the entry side of the
 *    morph itself, resolving the concrete silo model via `config('beam.taxonomy.models.silo')` so a host
 *    that subclasses BeamSilo gets facet attachment over its own model with no code change.
 *
 * **The issue-03 refinement (ADR-0165).** Classifying a *content* entry with `BeamSilo` is CORRECT and
 * intended — that is exactly this facet. The category error `.scratch/beamux-build/issues/03` warned
 * against was different: reusing the *compliance* `App\Models\Silo` for a *component's structural
 * namespace*. Namespace is disk grouping (S2); silo-classification is a content facet (S7). Distinct axes.
 *
 * Composition seam (ADR-0092): the facet attachment on the entry is beam-ux's (this concern);
 * `BeamSilo`/`BeamTag` themselves are the sibling beam-taxonomy family's.
 */
trait HasFacets
{
    use HasTags;

    /**
     * The concrete, host-bound silo model class. Resolved from config so this concern never hardcodes a
     * model FQCN — the same parameterization point beam-taxonomy's {@see HasTags::tagModel()} uses.
     *
     * @return class-string
     */
    protected static function siloModel(): string
    {
        return config('beam.taxonomy.models.silo');
    }

    /**
     * The OPTIONAL hierarchical classification silos this entry sits in (many-to-many via the `siloable`
     * morph). Empty for a fragment; filled for classified content. Mirrors tower's `HasSilos::silos()`
     * shape, but package-local and model-parameterized (down-only: beam-ux → beam-taxonomy).
     */
    public function silos(): MorphToMany
    {
        return $this->morphToMany(static::siloModel(), 'siloable');
    }
}
