<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The BeamUx authoring **entry** — the paid `splicewire/*` engine's central model (ADR-0092 vendor
 * seam). It is `BeamUxEntry`, never `BeamUxComponent`: the base spans authored **content** AND reusable
 * **components** (a `layout` is not a component), so a "component"-named base was a category error
 * (charter PRD §Q1, `beamux-entry-charter/issues/01`).
 *
 * **Composition, not inheritance — the own-model-*has-a*-particle shape (charter §Q1, ADR-0169).** The
 * entry does NOT extend {@see BeamParticle} and does NOT `use PersistsBeamParticle` on its own table.
 * Instead it **has-a** a generic {@see BeamParticle} row via a `particle_id` FK (two rows + a join per
 * entry, cost accepted): the particle carries the versioned, migrate-on-read **body** (schema-typed,
 * snapshot-versioned — the whole ADR-0138 beam-core discipline for free), while this row carries the
 * flat, queryable **authoring envelope**. The body is written through beam-core's shared
 * {@see ParticleWriter} against the related particle — NO fork of the write
 * pipeline, NO row-level restructuring of the particle.
 *
 * **Authoring envelope (S0 — the base columns only).** `slug` · `schema_ref` · `facade_ref` (nullable —
 * resolves to nothing for single-rendering entries; a multi-rendered canonical resolves its facade lens
 * invisibly, ADR-0155) · `type` ∈ {layout, template, page, component} · `namespace` (dot-nestable *build*
 * grouping — drives disk placement only, NOT the URL/taxonomy) · `residency_mode`. The optional **aspects**
 * (file placement S2; containment/route S3; workflow S6; taxonomy S7) are additive columns the
 * later charter steps add.
 *
 * **The two orthogonal axes (S1, ADR-0164).** `type` is the ENFORCED structural axis (cast to
 * {@see UxType} — exactly one of layout/template/page/component). `format` is its **sibling** body-
 * language axis (cast to {@see UxFormat} — `tsx | mdx | …`), NOT a fifth `type`: the two compose as a
 * `{type, format}` matrix, so an mdx **page** and an mdx **component** both exist. The `format` picks
 * the {@see BodyCodec} that translates raw source ⇄ the particle body. `body_style` ({@see BodyStyle} —
 * `full | inline`) demotes to a **tsx-codec-local flavor** (auto-import preamble); it is meaningless for
 * mdx. "node" (bare JSX) is a `component` in `body_style: inline`, never a fifth `type`.
 *
 * **Residency is context-following (charter §Q7).** The `residency_mode` column defaults to
 * `context-following`: an entry lives wherever it is authored (central or a tenant schema). The table+model
 * shape is **ubiquitous from day one** — the migration ships in beam-ux's `database/migrations/shared` dir
 * and is registered into BOTH the central `migrate` and the tenant `tenants:migrate` passes, so
 * `beam_ux_entries` exists identically in central and every tenant. Only the central path is exercised for
 * now; tenant-resident BeamUx needs no rework later because the shape is already committed.
 */
class BeamUxEntry extends Model
{
    use HasUuids;

    protected $table = 'beam_ux_entries';

    /** The context-following default (charter §Q7): the entry lives wherever it is authored. */
    public const RESIDENCY_CONTEXT_FOLLOWING = 'context-following';

    protected $fillable = [
        'slug',
        'schema_ref',
        'facade_ref',
        'type',
        'format',
        'body_style',
        'namespace',
        'placement_ref',
        'driver_ref',
        'residency_mode',
        'particle_id',
    ];

    protected $attributes = [
        'residency_mode' => self::RESIDENCY_CONTEXT_FOLLOWING,
        'format' => UxFormat::Tsx->value,
    ];

    /**
     * The two axes are ENFORCED at the boundary by casting to their enums (ADR-0164): assigning a
     * `type` outside {layout, template, page, component} — or a `format` the enum does not name —
     * throws `ValueError` on save/hydrate, so an invalid value can never land in the row. `body_style`
     * casts to {@see BodyStyle} but is a tsx-codec-local flavor (nullable; meaningless for mdx).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UxType::class,
            'format' => UxFormat::class,
            'body_style' => BodyStyle::class,
        ];
    }

    /**
     * The versioned, migrate-on-read **body** this entry has-a: a generic {@see BeamParticle} row keyed
     * by `particle_id`. The body is what the shared {@see ParticleWriter} writes;
     * this method is how a read re-loads it through the particle reader (migrate-on-read intact).
     */
    public function particle(): BelongsTo
    {
        return $this->belongsTo(BeamParticle::class, 'particle_id');
    }

    /**
     * The {@see BodyCodec} for this entry's `format` — resolved from the container-bound
     * {@see CodecRegistry} by dispatch on the format axis (ADR-0164). This is how the entry translates
     * its raw source ⇄ the particle body it has-a: `encode` the author's text into a body the
     * `ParticleWriter` stores, `decode` a stored body back to source.
     */
    public function codec(): BodyCodec
    {
        return app(CodecRegistry::class)->for($this->format ?? UxFormat::Tsx);
    }
}
