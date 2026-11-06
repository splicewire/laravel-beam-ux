<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Builder;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\SiteMapIndex;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The Site map's read projection (owner-review-20261102 research 37, 37-T1): one row per page, carrying what the map's
 * node and page inspector show, joined on the server so no client repeats it.
 *
 *  - the place in the tree (`parentId`, `segment`, `navOrder`) and the public `url`;
 *  - publication: readers see `publishedReadable`, the draft is `headReadable` when `draftPending`;
 *  - `search`: the sitemap's OWN verdict ({@see \Splicewire\Beam\Ux\Sitemap\SitemapEligibility}, the predicate
 *    `EntrySitemapSource` yields from), not {@see SitemapHealthRowData}'s route × published × entitled shortcut;
 *  - `file`: the mirror verdict and the Release member a file repair addresses, central-only (D-37-d): null on a
 *    tenant's connection whatever DiagnosticsAbility says, and null for a reader without that ability;
 *  - `redirects`: the old addresses that lead here, each walked once as a guest; only what fails a guest is attention.
 *
 * Scaffolded by `splicewire:beam:make:particle-resource`. Read-only: nothing writes a page through the map; its door is
 * the one Entry editor (X8). The entries model's own policy admits the read, as it does `beam-ux-entry`'s.
 */
#[ParticleResource(
    key: 'beam-ux-site-map',
    backing: BeamUxEntry::class,
    data: SiteMapRowData::class,
    input: false,
    label: 'Site map',
    group: 'Content',
    icon: 'network',
    section: 'authoring',
    readOnly: true,
    publicRead: null,
)]
#[TypeScript, Title('Site map page')]
final class SiteMapRowData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'Title', sort: 0)]
        public ?string $title,
        public string $slug,
        public ?string $parentId,
        public ?string $segment,
        public ?int $navOrder,
        #[Column(label: 'URL', sort: 1)]
        public ?string $url,
        #[Description('The readable handle of the version readers see (`v12`), or null when never published.')]
        public ?string $publishedReadable,
        #[Description('The readable handle of the working HEAD (`v13`).')]
        public ?string $headReadable,
        public bool $draftPending,
        public SiteMapSearchData $search,
        #[Description('Central only (D-37-d); null on a tenant connection or for a reader without the diagnostics ability.')]
        public ?SiteMapFileData $file,
        public SiteMapRedirectsData $redirects,
    ) {}

    /** One row per page: the realm roots and every non-page entry (the map's "Site parts") are not rows. */
    public static function scope(Builder $query): Builder
    {
        return $query->where('type', UxType::Page->value)
            ->where(fn (Builder $q) => $q->whereNull('namespace')->orWhere('namespace', '!=', 'realms'));
    }

    public static function project(BeamUxEntry $model): self
    {
        return app(SiteMapIndex::class)->row($model, request()->user());
    }
}
