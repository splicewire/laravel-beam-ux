<?php

namespace Splicewire\Beam\Ux\Data;

use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\MirrorGitStatus;

/**
 * The Frame resource declaration for the "Files" tool (mirror-status-ui ticket 01) — one row per
 * `BeamUxEntry`, decorated with its resolved mirror path and {@see MirrorGitStatus} verdict. The
 * SAME zero-glue `ParticleResource` tier {@see \Splicewire\Beam\Ux\Data\BeamUxEntryData} and
 * {@see \Splicewire\Beam\Data\GitRepoData} use — `readOnly: true` because nothing writes a
 * `BeamUxEntry` through THIS surface (it's a decorated read of the SAME rows `beam-ux-entry` already
 * owns for writes), a distinct `key` so it shows up as its own list rather than duplicating that
 * resource's edit surface.
 *
 * `state` stays a plain string rather than a PHP enum (see {@see MirrorGitStatus::statusFor()} for the
 * closed vocabulary) — declaring the row's SHAPE is this class's job; tightening it to a backed enum is
 * a separate, later step if a select-with-inlined-options filter control is ever wanted over the
 * current text-equality one.
 *
 * No bespoke controller: dropping this annotated class into the package's scanned `discover_paths`
 * (the same scan {@see BeamUxEntryData} already rides) IS the registration, served by the generic
 * `ParticleController`/`FrameResourceController` — no per-endpoint route, envelope class, or
 * hand-rolled JSON shape (mirror-status-ui ticket 01 originally shipped a bespoke controller +
 * `#[ResponseFromData]` envelope; retired in favor of this once it was clear every row is nothing but
 * one `BeamUxEntry` plus computed decorations, i.e. exactly what `#[ParticleResource]` already exists
 * to serve).
 */
#[ParticleResource(
    key: 'beam-ux-mirror-status',
    model: BeamUxEntry::class,
    label: 'Files',
    group: 'Ops',
    icon: 'git-branch',
    section: 'ops',
    readOnly: true,
)]
class MirrorStatusRowData extends Data
{
    public function __construct(
        public string $id,
        #[Column(label: 'Namespace', sort: 0), Filterable(Exact::class), Sortable(default: true)]
        public ?string $namespace,
        #[Column(label: 'Slug', sort: 1), Filterable(Exact::class), Sortable]
        public string $slug,
        #[Column(label: 'Type', sort: 2), Filterable(Exact::class)]
        public ?string $type,
        #[Column(label: 'Path', sort: 3)]
        public ?string $path,
        #[Column(label: 'Exists', sort: 4)]
        public bool $exists,
        #[Column(label: 'Last Modified', sort: 5)]
        public ?string $lastModifiedAt,
        #[Column(label: 'State', sort: 6), Filterable(Exact::class)]
        public string $state,
    ) {}

    public static function project(BeamUxEntry $model): self
    {
        $base = [
            'id' => (string) $model->id,
            'namespace' => $model->namespace,
            'slug' => $model->slug,
            'type' => $model->type?->value,
        ];

        if ($model->particle_id === null) {
            return self::from([
                ...$base,
                'path' => null,
                'exists' => false,
                'lastModifiedAt' => null,
                'state' => 'not-yet-saved',
            ]);
        }

        $path = app(PlacementResolver::class)->resolve($model)->pathFor($model);

        return self::from([...$base, ...app(MirrorGitStatus::class)->statusFor($path)]);
    }
}
