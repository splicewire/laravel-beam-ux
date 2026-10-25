<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** Record Pages share the actual Collection read boundary; no author fallback or global record lookup. */
final class RecordPages
{
    public function __construct(private ProfileCollections $profiles) {}

    public function assertSettings(BeamUxEntry $entry, EntryPageSettingsData $settings): void
    {
        $declaration = $settings->recordPage;
        $parameter = self::parameter($settings->segment);
        if ($declaration === null) {
            if ($parameter !== null || str_contains($settings->segment ?? '', '{')) {
                $this->refuse('A parameter segment needs a Record Page declaration.');
            }

            return;
        }
        if ($parameter !== $declaration->field) {
            $this->refuse('The segment must be exactly {field} for the declared unique field.');
        }
        if (! Schema::connection($entry->getConnectionName())->hasColumn('beam_ux_entries', 'record_page')) {
            $this->refuse('Migrate beam-ux before saving a Record Page.');
        }
        $source = $this->profiles->require($entry, $declaration->collection);
        $stem = $source->address['collection'] ?? null;
        if (! is_string($stem) || $source->parentEntryId !== null) {
            $this->refuse('A Record Page requires an offered schema Collection.');
        }
        $definition = app(CollectionSchemaResolver::class)->resolve($stem);
        $property = $definition->properties()[$declaration->field] ?? [];
        if (($property['type'] ?? null) !== 'string' || ! in_array($declaration->field, $definition->uniqueFields(), true)) {
            $this->refuse('The URL field must be a declared unique string field of the Collection.');
        }
        foreach ([$declaration->titleField, $declaration->descriptionField, $declaration->socialImageField] as $field) {
            if ($field !== null && ($definition->properties()[$field]['type'] ?? null) !== 'string') {
                $this->refuse('Title and SEO bindings must name declared string fields.');
            }
        }
        foreach ($entry->newQuery()->whereKeyNot($entry->getKey())->orderBy('id')->lockForUpdate()->get() as $other) {
            if (! array_intersect((array) ($entry->realms ?? [$entry->realm]), (array) ($other->realms ?? [$other->realm]))) {
                continue;
            }
            // A pending declaration reserves its address too. Never read it when serving the public page.
            $working = $other->particle?->meta['pageSettings'] ?? null;
            $otherDeclaration = is_array($working) ? ($working['recordPage'] ?? null) : $other->getAttribute('record_page');
            $otherSegment = is_array($working) ? ($working['segment'] ?? null) : $other->segment;
            $otherParent = is_array($working) ? ($working['parentId'] ?? null) : $other->parent_id;
            if ($otherParent === $settings->parentId && self::parameter($otherSegment) !== null) {
                $this->refuse('A parent may have only one parameter child.');
            }
            if (is_array($otherDeclaration)) {
                $otherSource = $this->profiles->sources($other)[$otherDeclaration['collection'] ?? ''] ?? null;
                if (($otherSource?->address['collection'] ?? null) === $stem) {
                    $this->refuse('A Collection may have only one Record Page per site.');
                }
            }
        }
    }

    /** Validation gets the proposed/saved version's declaration without persisting draft routing. */
    public function validationEntry(BeamUxEntry $entry, ?array $settings = null, bool $working = true): BeamUxEntry
    {
        $copy = clone $entry;
        if ($settings === null && $working) {
            $settings = $copy->particle?->meta['pageSettings'] ?? null;
        }
        if ($settings !== null) {
            $copy->setAttribute('record_page', $settings['recordPage'] ?? null);
        }

        return $copy;
    }

    public static function parameter(?string $segment): ?string
    {
        return preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_.-]*)\}$/D', $segment ?? '', $match) === 1 ? $match[1] : null;
    }

    /** Only a single safe URL piece; slash/dot traversal and empty values never become addresses. */
    public static function slug(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[\pL\pN][\pL\pN_-]*$/Du', $value) === 1 ? $value : null;
    }

    /** @return iterable<array<string,mixed>> The same guarded and projected rows as the visitor's Collection list. */
    public function records(BeamUxEntry $page, Request $request, string $realm): iterable
    {
        $declaration = $page->getAttribute('record_page');
        if (! is_array($declaration)) {
            return;
        }
        $source = $this->profiles->require($page, $declaration['collection']);
        $resource = app(ParticleResourceRegistry::class)->get($source->resource);
        try {
            for ($offset = 0; ; $offset += 100) {
                $rows = app(BindingRequestScope::class)->run($request, $source->address,
                    function (Request $read) use ($resource, $realm, $offset) {
                        app(\Splicewire\Beam\Authorization\ResourceReadGuard::class)->bindReadContext($resource, $read);

                        return app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                            ->indexWindow($resource->toResourceDefinition(), 100, $offset);
                    });
                foreach ($rows as $row) {
                    if (self::slug(Arr::get($row, 'fields.'.$declaration['field'])) !== null) {
                        yield $row;
                    }
                }
                if (count($rows) < 100) {
                    break;
                }
            }
        } catch (AuthorizationException) {
            return;
        }
    }

    public function resolve(BeamUxEntry $page, string $value, Request $request, string $realm): ?array
    {
        $field = $page->getAttribute('record_page')['field'] ?? null;
        if (! is_string($field) || self::slug($value) === null) {
            return null;
        }
        foreach ($this->records($page, $request, $realm) as $row) {
            if (Arr::get($row, 'fields.'.$field) === $value) {
                $row['url'] = $this->url($page, $row);

                return $row;
            }
        }

        return null;
    }

    public function url(BeamUxEntry $page, array $row): ?string
    {
        $declaration = $page->getAttribute('record_page');
        $slug = self::slug(Arr::get($row, 'fields.'.($declaration['field'] ?? '')));
        $url = $page->url();

        return $slug === null || $url === null ? null : str_replace('{'.$declaration['field'].'}', rawurlencode($slug), $url);
    }

    public function pageFor(BeamUxEntry $context, CollectionSource $source): ?BeamUxEntry
    {
        if (! isset($source->address['collection']) || ! Schema::connection($context->getConnectionName())->hasColumn('beam_ux_entries', 'record_page')) {
            return null;
        }
        $matches = [];
        foreach ($context->newQuery()->whereNotNull('record_page')->get() as $page) {
            if (! array_intersect((array) ($context->realms ?? [$context->realm]), (array) ($page->realms ?? [$page->realm]))) {
                continue;
            }
            $offer = $this->profiles->sources($page)[$page->getAttribute('record_page')['collection'] ?? ''] ?? null;
            if (($offer?->address['collection'] ?? null) === $source->address['collection']) {
                $matches[] = $page;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['pageSettings.recordPage' => $message]);
    }
}
