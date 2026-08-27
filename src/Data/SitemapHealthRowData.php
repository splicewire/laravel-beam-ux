<?php

namespace Splicewire\Beam\Ux\Data;

use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The Frame resource declaration for the "Sitemap" health dashboard (operator-surface-prototypes
 * ticket, Direction C step 2) — one row per `BeamUxEntry`, decorated with the SAME three gates
 * {@see \Splicewire\Beam\Ux\Sitemap\EntrySitemapSource::urls()} checks before yielding a crawlable
 * `Url` (route × published-marking × entitlement, ADR-0166). `indexed` is exactly that AND —
 * "is this entry actually in the live sitemap right now" — the health signal an operator scans for.
 * Same `#[ParticleResource(readOnly: true)]` tier {@see MirrorStatusRowData}/{@see GitRepoData} ride,
 * same `group`/`section: 'ops'` so both land together in Admin's Ops nav group.
 *
 * `routable` duplicates `EntrySitemapSource::routable()`'s two-line check rather than calling it — that
 * method is private, and a two-line boolean isn't worth widening a private method's visibility or
 * extracting a shared helper for two call sites. `published`/`entitled` delegate to the SAME
 * `EntryPublishGate`/`EntryEntitlementGate` ports the real sitemap feed reads, so this dashboard can
 * never drift from what's actually crawlable — a host that re-binds either port (its own workflow
 * lifecycle, its own entitlement authority) changes both surfaces identically, no source-side edit here.
 */
#[ParticleResource(
    key: 'beam-ux-sitemap-health',
    backing: BeamUxEntry::class,
    label: 'Sitemap',
    group: 'Ops',
    icon: 'globe',
    section: 'ops',
    readOnly: true,
)]
#[TypeScript]
class SitemapHealthRowData extends Data
{
    public function __construct(
        public string $id,
        #[Column(label: 'Namespace', sort: 0), Filterable(Exact::class), Sortable(default: true)]
        public ?string $namespace,
        #[Column(label: 'Slug', sort: 1), Filterable(Exact::class), Sortable]
        public string $slug,
        #[Column(label: 'Type', sort: 2), Filterable(Exact::class)]
        public ?string $type,
        #[Column(label: 'Routable', sort: 3)]
        public bool $routable,
        #[Column(label: 'Published', sort: 4), Filterable(Exact::class)]
        public bool $published,
        #[Column(label: 'Entitled', sort: 5)]
        public bool $entitled,
        #[Column(label: 'Indexed', sort: 6), Filterable(Exact::class)]
        public bool $indexed,
        #[Column(label: 'URL', sort: 7)]
        public ?string $url,
    ) {}

    public static function project(BeamUxEntry $model): self
    {
        $routable = $model->realm !== null && ! empty($model->realms) && $model->type === UxType::Page;
        $published = app(EntryPublishGate::class)->isPublished($model);
        $entitled = app(EntryEntitlementGate::class)->isPublic($model);

        return self::from([
            'id' => (string) $model->id,
            'namespace' => $model->namespace,
            'slug' => $model->slug,
            'type' => $model->type?->value,
            'routable' => $routable,
            'published' => $published,
            'entitled' => $entitled,
            'indexed' => $routable && $published && $entitled,
            'url' => $routable ? app(SitemapBaseUrlResolver::class)->baseUrl().$model->url() : null,
        ]);
    }
}
