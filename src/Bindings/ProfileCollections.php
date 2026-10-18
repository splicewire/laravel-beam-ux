<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

final class ProfileCollections
{
    /** @return array<string, CollectionSource> Explicit offers, recomputed in the entry's current host context. */
    public function sources(BeamUxEntry $entry, string $profile = 'content'): array
    {
        $sources = [];
        if (! app()->bound(ManifestCollectionSource::class)) {
            return $sources;
        }
        foreach (app(ManifestCollectionSource::class)->collections($entry, $profile) as $source) {
            if (! $source instanceof CollectionSource || ! preg_match('/^[a-z][a-z0-9-]*$/D', $source->name)
                || isset($sources[$source->name])) {
                throw new LogicException('Profile Collections require unique declared names.');
            }
            $sources[$source->name] = $source;
        }

        return $sources;
    }

    public function require(BeamUxEntry $entry, string $name): CollectionSource
    {
        return $this->sources($entry)[$name]
            ?? throw ValidationException::withMessages(['repeat.collection' => 'The Collection is not offered by this Manifest Profile.']);
    }

    /** Field vocabulary comes from the owning resource/schema, never a second handwritten schema. */
    public function manifest(BeamUxEntry $entry, string $profile): array
    {
        $out = [];
        foreach ($this->sources($entry, $profile) as $source) {
            $resource = app(ParticleResourceRegistry::class)->get($source->resource);
            $schema = isset($source->address['collection'])
                ? app(CollectionSchemaResolver::class)->resolve($source->address['collection'])->schema
                : app(Generator::class)->forResponse()->generate(new ReflectionClass($resource->data));
            $out[] = ['name' => $source->name, 'schema' => $schema, 'hasRecordPage' => false];
        }

        return $out;
    }
}
