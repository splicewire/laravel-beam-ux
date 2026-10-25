<?php

namespace Splicewire\Beam\Ux\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Redirect\RedirectService;

/**
 * A site's Redirects (ADR-0236 d1, d5, d14): each one's path, target, kind and origin. The Redirects seat lists, adds,
 * edits and removes them; managing them takes the rights publishing takes on the site.
 */
#[ParticleResource(key: 'beam-ux-redirect', backing: BeamUxRedirect::class, input: RedirectInputData::class,
    label: 'Redirects', group: 'Content', section: 'authoring', policy: 'ux.author', publicRead: null)]
#[TypeScript, Title('Redirect')]
final class RedirectData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'From', sort: 0)]
        public string $path,
        public string $match,
        public string $targetKind,
        /** The target as the list shows it: a page at its current URL, a path, an address, or a Record as collection:id. */
        #[Column(label: 'To', sort: 1)]
        public ?string $target,
        public ?string $targetEntryId,
        public ?string $targetTitle,
        #[Column(label: 'Permanent', sort: 2)]
        public bool $permanent,
        #[Column(label: 'Origin', sort: 3)]
        public string $origin,
        public ?string $originRef,
        public ?string $createdBy,
    ) {}

    public static function scope(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->getQualifiedKeyName());
    }

    public static function project(Model $model): self
    {
        /** @var BeamUxRedirect $model */
        $page = $model->target_kind === 'page' && $model->target_entry_id !== null
            ? BeamUxEntry::on($model->getConnectionName())->find($model->target_entry_id) : null;
        $target = match ($model->target_kind) {
            'page' => $page?->url(),
            'path' => $model->target_path,
            'address' => $model->target_url,
            'record' => $model->target_collection.':'.$model->target_record_id,
            default => null,
        };

        return new self((string) $model->getKey(), $model->path, $model->match, $model->target_kind, $target,
            $model->target_entry_id, $page?->title ?? $page?->slug, (bool) $model->permanent, $model->origin, $model->origin_ref,
            $model->created_by);
    }

    public static function prepare(Model $model, mixed $input, mixed $actor = null): void
    {
        /** @var BeamUxRedirect $model */
        app(RedirectService::class)->prepare($model, $input, $actor);
    }
}
