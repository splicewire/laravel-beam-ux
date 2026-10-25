<?php

namespace Splicewire\Beam\Ux\Bindings;

use LogicException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/** Read declarations from the publication pin only, never an author's mutable working body. */
class PublishedBindingDocument
{
    public function read(BeamUxEntry $entry): array
    {
        return $this->readVersion($entry, $entry->getAttribute('published_version'));
    }

    public function readPreview(BeamUxEntry $entry, PreviewContext $preview): array
    {
        return $this->readVersion($entry, $preview->versionFor((string) $entry->getKey()));
    }

    private function readVersion(BeamUxEntry $entry, ?string $pin): array
    {
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
