<?php

namespace Splicewire\Beam\Ux\Bindings;

use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Schema\SchemaVersionRegistering;
use Splicewire\Beam\Ux\Data\SchemaVersionImpactData;
use Splicewire\Beam\Ux\Data\SchemaVersionPageImpactData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/** Every normal runtime registration uses publication pins, on the registry's actual connection. */
class GuardSchemaRegistration
{
    public function __construct(private PublishedBindingDocument $documents, private SchemaBindingImpact $impact) {}

    public function __invoke(SchemaVersionRegistering $event): void
    {
        $incoming = SchemaId::from($event->schema['$id']);
        foreach ($event->versions as $schema) {
            if ((SchemaId::from($schema['$id'])->version() ?? 0) >= ($incoming->version() ?? 0)) {
                return; // Registering an older missing version does not change the latest binding target.
            }
        }
        // The authoring manifest is actor-gated. A queued registration instead uses the exact
        // same declared alias names over its actual registry, without granting manifest/read access.
        $addresses = (new \Splicewire\Beam\Collections\CollectionSchemaCatalog($event->registry))->addresses();
        $sources = RegisteredCollectionAliases::fromAddresses($addresses);
        $changedAliases = $this->changedAliases($event, $addresses, $sources);
        $pages = [];
        $aliasConflicts = [];
        foreach ($this->publishedPages($event) as $page) {
            $body = $this->documents->read($page);
            $aliases = array_values(array_intersect($this->impact->collections($body, $page->record_page), $changedAliases));
            if ($aliases !== []) {
                // Localization 23A (B10 U3): coded and keyed; the page's title and id and the aliases are parameters.
                $aliasConflicts[] = [BindingError::SchemaAliasRebindRequired, ['title' => (string) $page->title, 'page' => (string) $page->getKey(), 'aliases' => implode(', ', $aliases)]];

                continue;
            }
            $fields = $this->impact->fields($event->versions, $event->schema, $body, $sources, $page->record_page,
                fn (string $stem, string $relation) => app(CollectionSchemaResolver::class)->resolve($stem)->relations()[$relation]['collection'] ?? null);
            $dependencies = [];
            $parent = $page->parent_id === null ? null : $page->newQuery()->find($page->parent_id);
            if ($parent !== null && $parent->child_fields === $incoming->stem() && $parent->published_version !== null) {
                $renames = new DeclaredFieldRenames([...$event->versions, $event->schema]);
                foreach (array_keys($this->documents->pageSettings($page)['fields'] ?? []) as $field) {
                    if ($renames->property($field) === null) {
                        $fields[] = $field;
                    }
                }
                $dependencies[(string) $parent->getKey()] = $parent->published_version;
            }
            if ($fields !== []) {
                $fields = array_values(array_unique($fields));
                sort($fields, SORT_STRING);
                $pages[] = new SchemaVersionPageImpactData((string) $page->getKey(), (string) $page->title, $page->published_version, $fields, $dependencies);
            }
        }
        if ($aliasConflicts !== []) {
            // Not waivable: a confirmation never reaches past this refusal.
            throw \Splicewire\Beam\Ux\Localization\CodedRefusalList::on('schema.aliases', $aliasConflicts);
        }
        if ($pages === []) {
            return;
        }
        $impact = new SchemaVersionImpactData(SchemaFingerprint::of($event->schema, []), $pages);
        if ($event->confirmation === null || ! $event->confirmation->matches($event->schema, $impact->publishedVersions())) {
            throw new SchemaVersionImpactRequired($impact);
        }
    }

    /** Proposed alias comparison only; no artifacts are registered to discover the future vocabulary. */
    private function changedAliases(SchemaVersionRegistering $event, array $addresses, array $before): array
    {
        $stem = SchemaId::from($event->schema['$id'])->stem();
        $proposed = array_values(array_filter($addresses, fn ($address) => $address['stem'] !== $stem));
        if (array_key_exists('x-beam-collection', $event->schema)) {
            $proposed[] = ['stem' => $stem, 'label' => $stem]; // Labels do not participate in the shared naming contract.
        }
        $after = RegisteredCollectionAliases::fromAddresses($proposed);
        $changed = [];
        foreach ($before as $alias => $existingStem) {
            if (($after[$alias] ?? null) !== $existingStem) {
                $changed[] = $alias;
            }
        }
        // A pre-existing bare alias for a now-colliding group has no unambiguous stem. Do not guess.
        $base = array_key_first(RegisteredCollectionAliases::fromAddresses([['stem' => $stem, 'label' => $stem]]));
        $sameBase = 0;
        foreach ($addresses as $address) {
            if (array_key_first(RegisteredCollectionAliases::fromAddresses([$address])) === $base) {
                $sameBase++;
            }
        }
        if ($sameBase > 1 && ! isset($before[$base])
            && (array_key_exists('x-beam-collection', $event->schema) || in_array($stem, array_column($addresses, 'stem'), true))) {
            $changed[] = $base;
        }

        return array_values(array_unique($changed));
    }

    /** One read seam: production always enumerates published rows on the registration connection. */
    protected function publishedPages(SchemaVersionRegistering $event): iterable
    {
        $model = new BeamUxEntry;
        $model->setConnection($event->connection);
        if (! Schema::connection($event->connection)->hasTable($model->getTable())
            || ! Schema::connection($event->connection)->hasColumn($model->getTable(), 'published_version')) {
            return [];
        }

        return $model->newQuery()->where('type', UxType::Page->value)->whereNotNull('published_version')
            ->orderBy('id')->lazyById(100);
    }
}
