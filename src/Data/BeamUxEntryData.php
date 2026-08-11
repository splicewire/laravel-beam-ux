<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Data;
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
    model: BeamUxEntry::class,
    input: BeamUxEntryInputData::class,
    label: 'Entries',
    group: 'Content',
    icon: 'file-text',
    section: 'authoring',
)]
class BeamUxEntryData extends Data
{
    public function __construct(
        public string $id,
        #[Column(label: 'Type', sort: 0)]
        public string $type,
        #[Column(label: 'Title', sort: 1)]
        public ?string $title,
        #[Column(label: 'Slug', sort: 2)]
        public string $slug,
        #[Column(label: 'Realm', sort: 3)]
        public string $realm,
        public ?string $parent_id,
    ) {}

    /**
     * The per-kind default body (ADR-0016 — `@splicewire/beam-ux/blockdoc`'s Puck `Data` shape, not
     * raw `JsonNode[]`): `page`/`component` both start as the same empty Puck seed
     * (`{root: {}, content: [], zones: {}}` — `PuckPage.tsx`'s `EMPTY_SEED()`, the shape a
     * WYSIWYG-created `component` also composes through, no separate mechanism). A freshly created
     * tenant `theme` override pre-fills with the CURRENTLY-resolved theme (central row + schema
     * defaults, {@see ThemeResolver::resolve()}) — a real starting point to edit deltas from, never
     * blank.
     */
    public static function afterWrite(Model $model, mixed $input): void
    {
        if (! $model instanceof BeamUxEntry) {
            return;
        }

        // `root`/`zones` are JSON OBJECTS in Puck's Data shape ({root: {}, content: [], zones: {}},
        // PuckPage.tsx's EMPTY_SEED()) — a bare PHP `[]` always json_encodes as `[]`, never `{}`, so
        // an empty object needs the explicit (object) cast or the client sees the wrong JSON shape.
        $body = $model->type === UxType::Theme
            ? app(ThemeResolver::class)->resolve()
            : ['root' => (object) [], 'content' => [], 'zones' => (object) []];

        $item = app(StorageDriverResolver::class)->resolve($model)->write('', $body, $model->namespace);

        $model->particle_id = $item->key;
        $model->saveQuietly();
    }
}
