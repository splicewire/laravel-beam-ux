<?php

namespace Splicewire\Beam\Ux\Media;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Ux\Media\Data\EntryImageData;
use Splicewire\Beam\Ux\Media\Data\EntryImageLibraryData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Optional composition: no media classes load on hosts without the media provider. */
final class EntryMedia
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public static function available(): bool
    {
        return class_exists(\Splicewire\Beam\Media\BeamMediaServiceProvider::class)
            && app()->getProvider(\Splicewire\Beam\Media\BeamMediaServiceProvider::class) !== null;
    }

    public static function registerOwnerMorph(): void
    {
        $existing = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel(EntryMediaOwner::MORPH);
        if ($existing !== null && $existing !== EntryMediaOwner::class) {
            throw new \LogicException('The entry image owner morph is already assigned to another model.');
        }
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([EntryMediaOwner::MORPH => EntryMediaOwner::class]);
    }

    public function allowed(BeamUxEntry $entry, mixed $actor): bool
    {
        return self::available() && $actor instanceof Authenticatable
            && app(AbilityResolver::class)->allows($actor, 'ux.author')
            && Gate::forUser($actor)->allows('update', $entry);
    }

    private function authorize(BeamUxEntry $entry, mixed $actor): void
    {
        abort_unless(self::available(), 404);
        abort_unless($this->allowed($entry, $actor), 403);
    }

    public function library(BeamUxEntry $entry, mixed $actor): EntryImageLibraryData
    {
        $this->authorize($entry, $actor);
        $items = EntryMediaOwner::forEntry($entry)->getMedia('entry-images')
            ->filter(fn ($media) => in_array($media->mime_type, self::MIMES, true))
            ->map(fn ($media) => EntryImageData::fromMedia($media))->values()->all();

        return new EntryImageLibraryData($items);
    }

    public function upload(BeamUxEntry $entry, UploadedFile $file, mixed $actor): EntryImageData
    {
        $this->authorize($entry, $actor);
        // Inspect bytes, never trust a filename or browser Content-Type. SVG/HTML and remote URLs are not uploads.
        if (! $file->isValid() || ! in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()), self::MIMES, true) || $file->getSize() > 8 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Choose a JPEG, PNG, GIF, WebP or AVIF image up to 8 MiB.']);
        }
        $media = EntryMediaOwner::forEntry($entry)->addMedia($file)->toMediaCollection('entry-images');

        return EntryImageData::fromMedia($media);
    }
}
