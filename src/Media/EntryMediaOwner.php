<?php

namespace Splicewire\Beam\Ux\Media;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Optional adapter over the SAME entry row; it never creates an owner or accepts a client morph. */
final class EntryMediaOwner extends BeamUxEntry implements HasMedia
{
    use InteractsWithMedia;

    // A separate adapter token keeps Spatie's conversion rehydration on HasMedia, without remapping the base Entry.
    public const MORPH = 'beam_ux_entry_image_owner';

    public static function forEntry(BeamUxEntry $entry): self
    {
        $owner = new self;
        $owner->setConnection($entry->getConnectionName());
        $owner->setRawAttributes($entry->getAttributes(), true);
        $owner->exists = $entry->exists;

        return $owner;
    }

    public function getMorphClass(): string
    {
        return self::MORPH;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('entry-images')->acceptsMimeTypes(EntryMedia::MIMES);
    }
}
