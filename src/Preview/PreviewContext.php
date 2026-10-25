<?php

namespace Splicewire\Beam\Ux\Preview;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxPreviewGrant;

/** Request-local authority for a Preview. It is constructed only after the grant is revalidated. */
final readonly class PreviewContext
{
    public function __construct(public BeamUxPreviewGrant $grant, public ?Authenticatable $viewer = null) {}

    /** A signed-in viewer is allowed only on the central origin; a Site Host always reads as guest. */
    public static function centralViewer(Request $request): ?Authenticatable
    {
        $viewer = $request->user();
        if (! $viewer instanceof Authenticatable) {
            return null;
        }

        $tenancy = app(TenancyContext::class);

        return $tenancy->isActive() ? null : $viewer;
    }

    /** A grant is meaningful only in the same ambient storage scope as the row it names. */
    public function sharesStorageWith(BeamUxEntry $entry): bool
    {
        return $this->grant->getConnection()->getName() === $entry->getConnection()->getName();
    }

    public function contains(string $entryId): bool
    {
        if ($this->grant->release_id === null) {
            return $this->grant->entry_id === $entryId;
        }

        return $this->grant->release?->members()
            ->where('entry_id', $entryId)
            ->exists() ?? false;
    }

    /** The fixed version for a release member, or the one versioned entry grant. */
    public function versionFor(string $entryId): ?string
    {
        if ($this->grant->release_id === null) {
            if ($this->grant->entry_id === $entryId) {
                return $this->grant->version_id;
            }

            return BeamUxEntry::query()->whereKey($entryId)->value('published_version');
        }

        $member = $this->grant->release?->members()->where('entry_id', $entryId)->first();

        return $member === null
            ? BeamUxEntry::query()->whereKey($entryId)->value('published_version')
            : $member->version_id;
    }

    /** A saved preview version may admit its draft marking; a take-down has no version and stays absent. */
    public function admitsDraft(string $entryId): bool
    {
        return $this->contains($entryId) && $this->versionFor($entryId) !== null;
    }

    /** Rehydrates an entry and particle into an in-memory snapshot; this never writes either model. */
    public function project(BeamUxEntry $entry): ?BeamUxEntry
    {
        $versionId = $this->versionFor((string) $entry->getKey());
        if ($versionId === null) {
            return $this->contains((string) $entry->getKey()) ? null : $entry;
        }
        if ($entry->particle === null) {
            return null;
        }

        $particle = clone $entry->particle;
        $version = app(VersionStore::class)->version($particle, $versionId);
        if ($version === null) {
            return null;
        }

        $snapshot = $version->snapshot;
        $particle->forceFill([
            'schema_ref' => $snapshot['schema_ref'] ?? null,
            'schema_id' => $snapshot['schema_id'] ?? null,
            'migration_status' => $snapshot['migration_status'] ?? null,
            'payload' => $snapshot['payload'] ?? null,
            'meta' => $snapshot['meta'] ?? null,
        ]);

        $projected = clone $entry;
        $projected->setRelation('particle', $particle);

        $settings = $snapshot['meta']['pageSettings'] ?? null;
        if (is_array($settings)) {
            $projected->forceFill([
                'title' => $settings['title'] ?? $projected->title,
                'parent_id' => $settings['parentId'] ?? $projected->parent_id,
                'segment' => $settings['segment'] ?? $projected->segment,
                'nav_order' => $settings['navOrder'] ?? $projected->nav_order,
                'nav_group' => $settings['navGroup'] ?? $projected->nav_group,
                'layout' => $settings['layout'] ?? $projected->layout,
                'template' => $settings['template'] ?? $projected->template,
                'record_page' => $settings['recordPage'] ?? null,
                'seo_description' => $settings['description'] ?? $projected->seo_description,
                'seo_social_image' => $settings['socialImage'] ?? $projected->seo_social_image,
                'seo_page_type' => $settings['pageType'] ?? $projected->seo_page_type,
                'seo_published_date' => $settings['publishedDate'] ?? $projected->seo_published_date,
            ]);
        }

        return $projected;
    }
}
