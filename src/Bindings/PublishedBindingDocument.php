<?php

namespace Splicewire\Beam\Ux\Bindings;

use LogicException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/** Read declarations from the publication pin only, never an author's mutable working body. */
class PublishedBindingDocument
{
    public function read(BeamUxEntry $entry, ?PreviewContext $preview = null): array
    {
        $pin = $preview === null
            ? $entry->getAttribute('published_version')
            : $preview->versionFor((string) $entry->getKey());
        if ($pin === null || $entry->particle_id === null) {
            return [];
        }
        $body = app(VersionStore::class)->version($entry->particle, (string) $pin)?->snapshot['payload'] ?? null;
        if (! is_array($body)) {
            throw new LogicException('The published entry has no valid body snapshot for binding.');
        }

        return $body;
    }
}
