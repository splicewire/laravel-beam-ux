<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rushing\PermissionCascade\Concerns\HasVisibility;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Revisions\RevisionRecorder;
use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Models\Concerns\HasFacets;
use Splicewire\Beam\Ux\Sitemap\SiloVisibilityEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
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
 * **Containment aspect (S3 — ADR-0165, the "two trees").** `realm` + `realms` + `parent_id` +
 * `segment` place the entry in a rooted containment tree. That tree — NOT `namespace`
 * (disk-only) — derives the entry's PUBLIC URL: `segment` composes DOWN the tree from the realm
 * root via {@see UrlResolver} (bare/`./segment` = parent-relative folder semantics; `/segment` =
 * realm-root absolute). `realms` is the ordered fallback stack (theme-entries-and-authoring ticket
 * 03) an entry is reachable under — defaults to `[realm]` on create. Same `list<string>` shape as
 * `Splicewire\Beam\Realm\RealmRegistry::$stack`, but a DIFFERENT resolution: `RealmRegistry::effective()`
 * walks a single realm's stack to find ONE effective source; `realms` is a flat multi-membership list
 * (an entry is reachable under EVERY realm it names, not the last-resolvable-wins fallback that idiom
 * performs). Each realm auto-provisions exactly one canonical root entry via {@see self::rootFor()}.
 * Silo/Tags do NOT participate here (S7).
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
 *
 * **Workflow subject (S6 — OPTIONAL, ADR-0092 vendor seam).** The entry implements the free-tier
 * `laravel-beam-workflows` {@see WorkflowManaged} contract, so it is a subject the generic workflow
 * engine can govern — but only when a `Binding` is registered for its `type` (`page`, `component`, …)
 * on the package's `WorkflowBindingRegistry`. NO binding ⇒ NO workflow: the entry is unmanaged, exactly
 * as before, never forced through a state machine. The current marking persists on the `workflow_marking`
 * column (the package's "marking rides the host's typed port envelope" design); the definition version
 * it pins on first transition lives on `workflow_version`. `workflowType()` keys the resolver off the
 * entry's structural `type` axis, so a host binding on `page` governs every page while `component`
 * entries stay unmanaged unless separately bound. The persisted marking is EXACTLY what the sitemap /
 * routing publish gate reads for visibility (S6 replaces S4's `AlwaysPublishedGate` stub). The engine
 * (guards, versioned definitions, activitylog Display projection) comes for free from the package; this
 * model owns only the subject shape (the paid arm).
 *
 * **Classification facets (S7 — OPTIONAL, ADR-0165 §2).** Via {@see HasFacets} the entry attaches the
 * free-tier beam-taxonomy `BeamSilo` (`silos()`, hierarchical, `siloable` morph) + `BeamTag` (`tags()`,
 * flat, `taggable` morph) as OPTIONAL polymorphic classification — null for fragments, filled for content.
 * These are FACETS, not the spine: they drive filtering / related / secondary-nav and a silo's visibility
 * MAY gate the sitemap.xml feed (see {@see SiloVisibilityEntitlementGate}), but they
 * NEVER touch the canonical URL — that is containment's job (`realm`/`realms`/`parent_id`/`segment`).
 * Both morphs are pivot-based (`taggables`/`siloables` from beam-taxonomy's tables), so S7 adds NO column
 * to `beam_ux_entries`. Classifying a *content* entry with `BeamSilo` is correct (the issue-03 refinement);
 * only reusing the *compliance* silo for a component's structural *namespace* was the category error. The
 * facet attachment on the entry is paid `splicewire/*`; `BeamSilo`/`BeamTag` are free-tier (ADR-0092).
 *
 * **Draft schema inference (S9 — DRAFT, never final, ADR-0138).** A freshly-registered `component` is
 * editable immediately because {@see InferDraftSchema} deterministically
 * infers a JSON-Schema from its `.tsx` props and stores it as `schema_ref`, marking the record with
 * `schema_is_draft = true`. The draft flag is the load-bearing rule: inference produces the honest FLOOR
 * (name→key, TS type→JSON type, literal-union→enum, `?`→optional, destructure-default→`default`,
 * JSDoc→`title`/`description`) and NEVER fabricates widgets, validation, or `$ref`s — those stay explicit
 * authoring acts. Graduation (clearing `schema_is_draft`) is a separate deliberate step, never a side
 * effect of inference. `page`/`layout`/`template` are EXCLUDED: their schemas come from the composition
 * model (slots/regions), not props. The inference engine + draft schema-ref = paid `splicewire/*`; the
 * particle body it rides = free beam-core (ADR-0092 vendor seam).
 */
class BeamUxEntry extends Model implements WorkflowManaged
{
    use HasFacets;
    use HasUuids;
    use HasVisibility;
    use SoftDeletes;
    use WorkflowManagedTrait;

    protected $table = 'beam_ux_entries';

    /** The context-following default (charter §Q7): the entry lives wherever it is authored. */
    public const RESIDENCY_CONTEXT_FOLLOWING = 'context-following';

    /** The public content realm an entry roots its URL under by default (the `site` realm, ADR-0165). */
    public const REALM_SITE = 'site';

    /**
     * The marking a bound entry must sit at to count as publicly visible (S6). The published-marking
     * gate ({@see WorkflowMarkingPublishGate}) reads `workflow_marking`
     * against this; a host whose publish lifecycle names its live place differently re-binds the gate.
     */
    public const MARKING_PUBLISHED = 'published';

    protected $fillable = [
        'slug',
        // Human nav label (host-provided; nullable — absent everywhere else falls back to a humanized slug).
        'title',
        'schema_ref',
        // Draft-schema marker (S9): is `schema_ref` an INFERRED draft, not an authored/graduated spec?
        'schema_is_draft',
        'facade_ref',
        'type',
        'format',
        'body_style',
        'namespace',
        'placement_ref',
        'driver_ref',
        'residency_mode',
        'particle_id',
        // Containment aspect (S3, ADR-0165): the URL/nav tree, decoupled from `namespace` (disk).
        'realm',
        'realms',
        'parent_id',
        'segment',
        // Sibling nav order (host-provided; nullable — projector orders by it when the column is present).
        'nav_order',
        // Workflow aspect (S6): the optional beam-workflows subject envelope.
        'workflow_marking',
        'workflow_version',
    ];

    protected $attributes = [
        'residency_mode' => self::RESIDENCY_CONTEXT_FOLLOWING,
        'realm' => self::REALM_SITE,
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
            // The inferred-draft marker (S9): true only when `schema_ref` is an inference draft.
            'schema_is_draft' => 'boolean',
            // The ordered realm fallback stack (ticket 03) — a list<string>, same shape as
            // RealmRegistry::$stack.
            'realms' => 'array',
        ];
    }

    /**
     * Default a new entry's `realms` fallback stack onto `[realm]` (ticket 03). Only fires when the
     * entry has a realm and no stack was set explicitly — so a host that authors a multi-realm entry
     * directly is never overridden. Also records soft-delete/restore as ordinary
     * {@see RevisionRecorder} transitions (ticket 06) — audit continuity independent of the OPTIONAL
     * workflow aspect (S6), which governs a `workflow_marking` state machine, not delete/restore.
     */
    protected static function booted(): void
    {
        static::creating(function (BeamUxEntry $entry): void {
            // `format` defaults by `type`, not a single fleet-wide constant (found live: a `theme`
            // entry left at the old flat `tsx` default silently resolved TsxBodyCodec, which returns
            // an empty string for a `{canvas, site}` body — every theme save was writing a blank
            // `.tsx` file to the disk mirror with no error). A host that sets `format` explicitly is
            // never overridden.
            $entry->format ??= self::defaultFormatFor($entry->type);

            if (($entry->realms === null || $entry->realms === []) && $entry->realm !== null) {
                $entry->realms = [$entry->realm];
            }
        });

        static::deleted(function (BeamUxEntry $entry): void {
            // Eloquent fires `deleted` for BOTH a soft-delete AND a forceDelete() on a SoftDeletes
            // model — but forceDelete() never runs runSoftDelete(), so `deleted_at` stays whatever it
            // was before the call. Recording a transition here on a force-delete would log a
            // misleading 'deleted' revision claiming nothing changed, for a row that's actually gone.
            // Nothing in this package calls forceDelete() on an entry today (Frame's generic
            // destroy() just calls delete()), but the guard is cheap and correct either way.
            if ($entry->isForceDeleting()) {
                return;
            }

            app(RevisionRecorder::class)->record(
                $entry,
                ['deleted_at' => null],
                ['deleted_at' => $entry->deleted_at?->toISOString()],
                'deleted',
            );
        });

        static::restored(function (BeamUxEntry $entry): void {
            app(RevisionRecorder::class)->record(
                $entry,
                ['deleted_at' => $entry->getOriginal('deleted_at')],
                ['deleted_at' => null],
                'restored',
            );
        });
    }

    /**
     * The one canonical root entry for a realm — auto-provisioned on first ask (find-or-create), so
     * every configured realm resolves exactly one root without a seeder (ticket 03): the top of the
     * containment chain, and the coarse grant anchor a host's authority model grants `manage` on.
     * Identified structurally by the reserved `realms` build namespace + the realm as its slug — that
     * pair is already unique (`unique(namespace, slug)`), so no extra marker column is needed.
     */
    public static function rootFor(string $realm = self::REALM_SITE): self
    {
        return static::query()->firstOrCreate(
            ['namespace' => 'realms', 'slug' => $realm],
            ['type' => UxType::Page, 'title' => 'Realm root', 'realm' => $realm, 'realms' => [$realm]],
        );
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
     * The {@see Versionable} target for `Route::recordVersions()`'s `via:` resolution
     * (`splicewire/laravel-beam-versioning`). The entry itself is a plain envelope row — its
     * {@see BeamParticle} body is what actually carries the durable named-snapshot history
     * ({@see BeamParticle} already `implements Versionable`, ADR-0138). An entry with no particle
     * yet (never saved) returns null; the versions controller surfaces that as "not Versionable" —
     * there is nothing to version until the first save mints one.
     */
    public function versionable(): ?BeamParticle
    {
        return $this->particle;
    }

    /** The containing entry node (adjacency parent; the strict single home). Null = top-level. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** The child entry nodes contained directly under this one. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Feeds `rushing/laravel-permission-cascade`'s topology-agnostic `HasVisibility` concern its
     * ancestor chain (ACC-01): nearest-first, walking `parent_id` up to the containment root (a
     * realm root entry has none — an empty chain). This is what lets a Team's `manage` grant on a
     * REALM ROOT cascade down to every entry beneath it via
     * `Rushing\PermissionCascade\Policies\BaseModelPolicy::resolveShared()`.
     *
     * @return list<self>
     */
    public function visibilityAncestors(): iterable
    {
        if ($this->parent === null) {
            return [];
        }

        return [$this->parent, ...$this->parent->visibilityAncestors()];
    }

    /**
     * This entry's inherited PUBLIC URL — composed DOWN the containment tree from the realm root
     * (ADR-0165 §5), decoupled from `namespace` (disk-only). Delegates to the {@see UrlResolver}.
     */
    public function url(): string
    {
        return app(UrlResolver::class)->resolve($this);
    }

    /**
     * The {@see BodyCodec} for this entry's `format` — resolved from the container-bound
     * {@see CodecRegistry} by dispatch on the format axis (ADR-0164). This is how the entry translates
     * its raw source ⇄ the particle body it has-a: `encode` the author's text into a body the
     * `ParticleWriter` stores, `decode` a stored body back to source.
     */
    public function codec(): BodyCodec
    {
        return app(CodecRegistry::class)->for($this->format ?? self::defaultFormatFor($this->type));
    }

    /** `format`'s type-aware default (`creating()` stamps this in; `codec()` mirrors it as a fallback
     * for a row that predates this default, or one written by a raw insert bypassing Eloquent). */
    private static function defaultFormatFor(?UxType $type): UxFormat
    {
        return $type === UxType::Theme ? UxFormat::Css : UxFormat::Tsx;
    }

    /**
     * The stable workflow-type key this entry resolves through (S6 — the binding registry's selector).
     * Keyed off the entry's structural `type` axis (`page` / `component` / `layout` / `template`), so a
     * host binding a workflow to `page` governs EVERY page while other types stay unmanaged unless
     * separately bound. An entry with no `type` set resolves to the empty key, which the resolver reads
     * as unresolvable ⇒ unmanaged (the optional-workflow fallback).
     */
    public function workflowType(): string
    {
        $type = $this->type;

        if ($type instanceof UxType) {
            return $type->value;
        }

        return (string) ($type ?? '');
    }

    /**
     * The marking projects onto `workflow_marking`, NOT the trait's default `status` — the entry has no
     * `status` column; its lifecycle state is the dedicated S6 aspect column.
     */
    public function workflowStatusAttribute(): string
    {
        return 'workflow_marking';
    }
}
