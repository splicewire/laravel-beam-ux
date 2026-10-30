<?php

namespace Splicewire\Beam\Ux\Data;

use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\Widget;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\ParticleResource as BeamParticleResource;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsReadPolicy;
use Splicewire\Beam\Ux\Filters\ProjectedExact;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** One entry's site indexing and mirror state, from the existing diagnostic projections. */
#[ParticleResource(
    key: 'beam-ux-site-health', backing: BeamUxEntry::class, input: false,
    label: 'Site health', group: 'Content', section: 'authoring', icon: 'heart-pulse', readOnly: true,
    policy: DiagnosticsAbility::NAME, readPolicy: DiagnosticsReadPolicy::class,
    readBoundary: BeamParticleResource::READ_BOUNDARY_GLOBAL,
)]
#[TypeScript, Title('Site health')]
class SiteHealthRowData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'Namespace', sort: 0), Filterable(Exact::class), Sortable(default: true)]
        public ?string $namespace,
        #[Column(label: 'Slug', sort: 1), Filterable(Exact::class), Sortable]
        public string $slug,
        #[Column(label: 'Type', sort: 2), Filterable(Exact::class)]
        public ?string $type,
        #[Column(label: 'Indexed', sort: 3), Filterable(ProjectedExact::class)]
        public bool $indexed,
        #[Column(label: 'State', sort: 4), Filterable(ProjectedExact::class)]
        #[Widget('badge', options: ['labels' => ['mirror-disabled' => 'Mirror disabled']])]
        public string $state,
        #[Column(label: 'Routable', sort: 5)] public bool $routable,
        #[Column(label: 'Published', sort: 6)] public bool $published,
        #[Column(label: 'Entitled', sort: 7)] public bool $entitled,
        #[Column(label: 'URL', sort: 8)] public ?string $url,
        #[Column(label: 'Path', sort: 9)] public ?string $path,
        #[Column(label: 'Exists', sort: 10)] public bool $exists,
        #[Column(label: 'Last modified', sort: 11)] public ?string $lastModifiedAt,
    ) {}

    public static function project(BeamUxEntry $entry): self
    {
        $sitemap = SitemapHealthRowData::project($entry);
        $mirror = MirrorStatusRowData::project($entry);

        return new self($sitemap->id, $sitemap->namespace, $sitemap->slug, $sitemap->type,
            $sitemap->indexed, $mirror->state, $sitemap->routable, $sitemap->published,
            $sitemap->entitled, $sitemap->url, $mirror->path, $mirror->exists, $mirror->lastModifiedAt);
    }
}
