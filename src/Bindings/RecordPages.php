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

    private function memo(): RecordPageScope
    {
        return app(RecordPageScope::class);
    }

    public static function forgetScope(): void
    {
        app(RecordPageScope::class)->forgetPages();
    }

    private function storageKey(BeamUxEntry $entry): string
    {
        return ($entry->getConnectionName() ?? 'default').'|'.(string) app(\Splicewire\Beam\Realm\Contracts\TenancyContext::class)->currentTenantId();
    }

    public function hasPublished(BeamUxEntry $entry): bool
    {
        return $this->published($entry)->isNotEmpty();
    }

    private function published(BeamUxEntry $entry): \Illuminate\Support\Collection
    {
        $memo = $this->memo();
        $key = $this->storageKey($entry);
        $memo->columns[$key] ??= Schema::connection($entry->getConnectionName())->hasColumn('beam_ux_entries', 'record_page');

        return $memo->pages[$key] ??= $memo->columns[$key] ? $entry->newQuery()->whereNotNull('record_page')->get() : collect();
    }

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
        if (! $definition->hasPublicRead() || ! in_array($declaration->field, $definition->publicFieldNames(), true)) {
            $this->refuse('The Record Page URL field must be included in the Collection Public Read projection.');
        }
        foreach ([$declaration->titleField, $declaration->descriptionField, $declaration->socialImageField] as $field) {
            if ($field !== null && ($definition->properties()[$field]['type'] ?? null) !== 'string') {
                $this->refuse('Title and SEO bindings must name declared string fields.');
            }
        }
        // Same native schema anchor as Collection unique writes; serialize this Collection, never the whole entries table.
        $connection = $entry->getConnection();
        if (! $connection->getSchemaBuilder()->hasTable(\Splicewire\Beam\Facades\Beam::table('schemas'))) {
            $this->refuse('Record Pages require a registered database schema anchor.');
        }
        $anchor = $connection->table(\Splicewire\Beam\Facades\Beam::table('schemas'))
            ->where('schema_name', $definition->stem())->orderBy('version')->orderBy('id')->lockForUpdate()->first();
        if ($anchor === null) {
            $this->refuse('Record Pages require a registered database schema anchor.');
        }
        $others = $this->candidates($entry, $settings)->with('particle')->orderBy('id')->get();
        foreach ($others as $other) {
            if (! array_intersect((array) ($entry->realms ?? [$entry->realm]), (array) ($other->realms ?? [$other->realm]))) {
                continue;
            }
            $working = $other->particle?->meta['pageSettings'] ?? null;
            // A draft moving away never releases the address that is STILL published.
            $reservations = [['recordPage' => $other->record_page, 'segment' => $other->segment, 'parentId' => $other->parent_id]];
            if (is_array($working)) {
                $reservations[] = $working;
            }
            foreach ($reservations as $reservation) {
                if (($reservation['parentId'] ?? null) === $settings->parentId && self::parameter($reservation['segment'] ?? null) !== null) {
                    $this->refuse('A parent may have only one parameter child.');
                }
                $otherDeclaration = $reservation['recordPage'] ?? null;
                if (is_array($otherDeclaration)) {
                    $otherSource = $this->profiles->sources($other)[$otherDeclaration['collection'] ?? ''] ?? null;
                    if (($otherSource?->address['collection'] ?? null) === $stem) {
                        $this->refuse('A Collection may have only one Record Page per site.');
                    }
                }
            }
        }
    }

    /** Lock a selected PRIMARY KEY set in the SAME global order used by publish/publishVersions. */
    public function lockForSettings(BeamUxEntry $entry, EntryPageSettingsData $settings): void
    {
        if ($settings->recordPage === null) {
            return;
        }
        // Read the OR/JSON predicates without a lock: an InnoDB range lock here would cover the table.
        $ids = $this->lockIds($entry, $settings);
        $entry->newQuery()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($ids !== $this->lockIds($entry, $settings)) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Record Page declarations changed while acquiring locks. Retry against the current state.');
        }
        // assertSettings() takes the schema anchor AFTER these entry locks, as publication does.
        // It re-reads declarations after serialization; no predicate/range lock is used there.
    }

    /** @return list<string> */
    private function lockIds(BeamUxEntry $entry, EntryPageSettingsData $settings): array
    {
        $ids = $this->candidates($entry, $settings)->pluck('id')->all();
        $ids[] = $entry->getKey();
        if ($settings->parentId !== null) {
            $ids[] = $settings->parentId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function candidates(BeamUxEntry $entry, EntryPageSettingsData $settings): \Illuminate\Database\Eloquent\Builder
    {
        return $entry->newQuery()->whereKeyNot($entry->getKey())->where(function ($query) use ($settings) {
            $query->whereNotNull('record_page')
                ->orWhereHas('particle', fn ($particle) => $particle->whereNotNull('meta->pageSettings->recordPage'))
                ->orWhere(fn ($siblings) => $siblings->where('parent_id', $settings->parentId)->where('segment', 'like', '{%'));
        });
    }

    /** Validation gets the proposed/saved version's declaration without persisting draft routing. */
    public function validationEntry(BeamUxEntry $entry, ?array $settings = null, bool $working = true): BeamUxEntry
    {
        $copy = clone $entry;
        if ($settings === null && $working && $copy->particle_id !== null) {
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
    public function records(BeamUxEntry $page, Request $request, string $realm, ?string $value = null): iterable
    {
        $declaration = $page->getAttribute('record_page');
        if (! is_array($declaration)) {
            return;
        }
        try {
            for ($offset = 0; ; $offset += 100) {
                $rows = $this->readWindow($page, $request, $realm, 100, $offset, $value);
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

    /** A raw bounded first window: invalid URL values cannot cause an unbounded picker scan. */
    public function choiceWindow(BeamUxEntry $page, Request $request, string $realm, int $limit): array
    {
        return $this->readWindow($page, $request, $realm, max(1, min(26, $limit)), 0);
    }

    private function readWindow(BeamUxEntry $page, Request $request, string $realm, int $limit, int $offset, ?string $value = null): array
    {
        $declaration = $page->getAttribute('record_page');
        $source = $this->profiles->require($page, $declaration['collection']);
        $resource = app(ParticleResourceRegistry::class)->get($source->resource);
        $records = $value === null ? null : new RecordPageRecords(app(\Splicewire\Beam\Collections\StandaloneRecordQuery::class), $declaration['field'], $value);
        try {
            return app(BindingRequestScope::class)->run($request, $source->address,
                function (Request $read) use ($resource, $realm, $limit, $offset) {
                    app(\Splicewire\Beam\Authorization\ResourceReadGuard::class)->bindReadContext($resource, $read);

                    return app(ParticleFrameResourceHandler::class)->forRequest($read, $realm)
                        ->indexWindow($resource->toResourceDefinition(), $limit, $offset);
                }, $records);
        } catch (AuthorizationException) {
            return [];
        }
    }

    public function resolve(BeamUxEntry $page, string $value, Request $request, string $realm): ?array
    {
        $field = $page->getAttribute('record_page')['field'] ?? null;
        if (! is_string($field) || self::slug($value) === null) {
            return null;
        }
        foreach ($this->records($page, $request, $realm, $value) as $row) {
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
        if (! isset($source->address['collection'])) {
            return null;
        }
        $memo = $this->memo();
        $key = $this->storageKey($context).'|'.json_encode([$context->realms ?? [$context->realm], $source->address['collection']]);
        if (array_key_exists($key, $memo->matches)) {
            return $memo->matches[$key];
        }
        $matches = [];
        foreach ($this->published($context) as $page) {
            if (! array_intersect((array) ($context->realms ?? [$context->realm]), (array) ($page->realms ?? [$page->realm]))) {
                continue;
            }
            $offers = $memo->offers[$this->storageKey($page).'|'.$page->id] ??= $this->profiles->sources($page);
            $offer = $offers[$page->getAttribute('record_page')['collection'] ?? ''] ?? null;
            if (($offer?->address['collection'] ?? null) === $source->address['collection']) {
                $matches[] = $page;
            }
        }

        return $memo->matches[$key] = count($matches) === 1 ? $matches[0] : null;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['pageSettings.recordPage' => $message]);
    }
}
