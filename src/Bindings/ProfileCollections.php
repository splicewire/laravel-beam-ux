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
            if ($source->parentEntryId !== null) {
                $out[] = ['name' => $source->name, 'schema' => $this->fieldSchema($source), 'hasRecordPage' => true];

                continue;
            }
            $schema = $this->fieldSchema($source);
            $out[] = ['name' => $source->name, 'schema' => $schema, 'hasRecordPage' => app(RecordPages::class)->pageFor($entry, $source) !== null];
        }

        return $out;
    }

    /** The schema describes the actual Record envelope returned by its owner. */
    public function fieldSchema(CollectionSource $source): array
    {
        if ($source->parentEntryId !== null) {
            $schema = app(Generator::class)->forResponse()->generate(new ReflectionClass(\Splicewire\Beam\Ux\Data\ChildPageRecordData::class));
            // ADR-0233 d4: the Page Fields the parent declares are the Record fields beside the child's own.
            $parent = BeamUxEntry::query()->find($source->parentEntryId);
            $class = $parent === null || ! \Illuminate\Support\Facades\Schema::hasColumn('beam_ux_entries', 'child_fields')
                ? null : \Splicewire\Beam\Ux\Containment\PageFields::classNamed($parent->getAttribute('child_fields'));
            if ($class !== null) {
                $schema['properties']['fields'] = \Splicewire\Beam\Ux\Containment\PageFields::schema($class);
            }

            return $schema;
        }
        $resource = app(ParticleResourceRegistry::class)->get($source->resource);
        if (! isset($source->address['collection'])) {
            return app(Generator::class)->forResponse()->generate(new ReflectionClass($resource->data));
        }
        $fields = app(CollectionSchemaResolver::class)->resolve($source->address['collection'])->schema;

        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'],
            'fields' => $fields,
        ], '$defs' => $fields['$defs'] ?? [], 'definitions' => $fields['definitions'] ?? []];
    }
}
