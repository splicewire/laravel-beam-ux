<?php

namespace Splicewire\Beam\Ux\Publish;

use Splicewire\Beam\Ux\Codec\AcceptsJsonDoc;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/** Server authority for the body an unauthored entry already displays. Never accepts client input. */
class EntryInitialBody
{
    public function resolve(BeamUxEntry $entry): array
    {
        if ($entry->particle_id !== null) {
            $item = app(StorageDriverResolver::class)->resolve($entry)->read((string) $entry->particle_id);
            if ($item === null) {
                throw new \RuntimeException('The entry body is missing; repair it before initializing history.');
            }

            return $item->body;
        }
        if (! $entry->codec() instanceof AcceptsJsonDoc) {
            return [];
        }
        $defaults = json_decode(file_get_contents(__DIR__.'/../../resources/editor/default-trees.json'), true, flags: JSON_THROW_ON_ERROR);

        return $defaults[(string) $entry->slug] ?? [[
            'kind' => 'block', 'name' => 'div', 'isComponent' => false,
            'props' => [], 'children' => [], 'dynamic' => false,
        ]];
    }
}
