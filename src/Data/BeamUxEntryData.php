<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\ResourceRef;
use Schemastud\Frame\Attributes\RowActions;
use Schemastud\Frame\Attributes\Widget;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The Frame resource declaration for `BeamUxEntry` (theme-entries-and-authoring ticket 05) — both the
 * create mechanism AND the create-UI home. `ParticleResource`'s zero-glue Frame tier, the same one
 * `SitemapData` demonstrates: dropping this one annotated Data class into a scanned `discover_paths`
 * IS the wiring, served by the generic `FrameResourceController` — no hand-built controller.
 *
 * A HOST still owns registering this package's own `discover_paths`/`particle.classes` entry so its
 * own Frame boot actually finds this class (per-host `theme-host-wiring` ticket's "Frame nav
 * registration" scope item) — a package Data class doesn't land in a host's `app_path('Data')` scan
 * on its own.
 *
 * **`afterWrite`** (a static-method convention `AttributedParticleDiscovery` wires automatically,
 * fired by `ParticleWriter`'s `PersistStage` strictly AFTER the row is saved) mints the per-kind
 * default body — deliberately Frame-specific, not a `BeamUxEntry::created()` model event: this
 * package already has several OTHER creation paths (`ScaffoldCommand`, `RegisterEntriesFromDisk`,
 * tests) that intentionally leave `particle_id` unset, and a global model event would silently give
 * every one of them a body they never asked for.
 */
#[ParticleResource(
    key: 'beam-ux-entry',
    backing: BeamUxEntry::class,
    input: BeamUxEntryInputData::class,
    label: 'Entries',
    group: 'Content',
    icon: 'file-text',
    section: 'authoring',
)]
// Ticket 06: the row-actions manifest a consuming host's mounted RowActions component reads to know
// which actions this resource supports — 'promote-to-central' is CONDITIONAL client-side (rendered
// only when the viewer holds the required central-root grant; see EntryPromoter::authorized()), not
// filtered out of this static list, which just declares the action VOCABULARY.
#[RowActions(['edit', 'duplicate', 'delete', 'promote-to-central'])]
// #[Column] (below) drives the Entries LIST's table headers only — JsonSchemaGenerator (the edit
// FORM's source) never reads it, a separate seam entirely (found live: the edit panel read "UxType"
// for the type field and the bare class name "BeamUxEntryData" for its own heading, both from
// JsonSchemaGenerator falling back to raw PHP names with no #[Title] on this class to override).
#[TypeScript]
#[Title('UX Entry')]
class BeamUxEntryData extends Data
{
    public function __construct(
        public string $id,
        // The model casts `type` to the UxType enum (BeamUxEntry::casts()); a plain `string` hint
        // here throws when spatie/laravel-data hydrates straight off the model's already-cast
        // attribute (DataFromArrayResolver, not the raw DB column). spatie/laravel-data serializes
        // a native enum property to its ->value on the wire, so JSON consumers see the same plain
        // string either way — this only fixes the PHP-side type mismatch.
        #[Column(label: 'Type', sort: 0), Title('Type')]
        public UxType $type,
        #[Column(label: 'Title', sort: 1), Title('Title')]
        public ?string $title,
        #[Column(label: 'Slug', sort: 2), Title('Slug')]
        public string $slug,
        // A dropdown suggesting the realms actually in use, but not hard-restricted to them (a new
        // realm has to start as free text SOMEWHERE) — #[Widget('combobox')] renders a <datalist>-
        // backed input, not an enum <select>. Static list, not queried from RealmRegistry: a PHP
        // attribute's arguments must be compile-time literals, so this can drift from the registry —
        // acceptable for a suggestion list, worth reconsidering if it does.
        #[Column(label: 'Realm', sort: 3), Title('Realm'), Widget('combobox', options: ['suggestions' => ['site', 'operator', 'tenant', 'user']])]
        public string $realm,
        // A picker over OTHER beam-ux-entry rows (self-referential — the containment tree), not a
        // raw UUID paste. #[ResourceRef] fetches beam-ux-entry's own index for the option list.
        #[Title('Parent'), ResourceRef('beam-ux-entry', value: 'id', label: 'title')]
        public ?string $parent_id,
        // No #[Column] — not a table column, just carried on the wire so a client (the theme editor,
        // found live: a namespaced `theme` entry and a null-namespace `page` entry sharing one slug
        // resolved to the WRONG one server-side with no way for the client to disambiguate) can pass
        // it back to `beam.ux.entries.body.*` as the `?namespace=` qualifier. Suggests the two
        // reserved namespaces BeamUxEntry::rootFor()/ThemeResolver use (see this class's own
        // afterWrite) plus blank (no namespace) — same combobox-not-enum reasoning as realm above.
        #[Title('Namespace'), Widget('combobox', options: ['suggestions' => ['', 'realms', 'theme']])]
        public ?string $namespace = null,
    ) {}

    /**
     * The per-kind default body (ADR-0016 — `@splicewire/beam-ux/blockdoc`'s `JsonNode[]` tree, NOT
     * Puck): `page`/`component` both start as the same empty `JsonDoc` (`[]`, zero nodes) — the shape
     * `@splicewire/beam-ux/canvas`'s `VisualEditor`/`PageEditor`/`TreeRender` actually consume (that
     * module's own docblock names `JsonDoc` as its body type; Puck's `{root,content,zones}` shape,
     * this method previously — incorrectly — seeded, is retired fleet-wide). An author starts with a
     * blank canvas and inserts their first block via the editor's Insert palette — no separate
     * mechanism for `component` vs `page`. A freshly created tenant `theme` override pre-fills with
     * the CURRENTLY-resolved theme (central row + schema defaults, {@see ThemeResolver::resolve()}) —
     * a real starting point to edit deltas from, never blank; `theme` bodies are a distinct
     * `{canvas,shell,site}` token object, not a `JsonDoc`, and are unaffected by this.
     */
    public static function afterWrite(Model $model, mixed $input): void
    {
        if (! $model instanceof BeamUxEntry) {
            return;
        }

        $body = $model->type === UxType::Theme
            ? app(ThemeResolver::class)->resolve()
            : [];

        $item = app(StorageDriverResolver::class)->resolve($model)->write('', $body, $model->namespace);

        $model->particle_id = $item->key;
        $model->saveQuietly();
    }
}
