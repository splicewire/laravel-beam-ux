<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Ux\Bindings\BindingFieldEvolution;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ManifestCollectionSource;
use Splicewire\Beam\Ux\Bindings\ProfileCollections;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

final class BindingFieldEvolutionTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        Container::setInstance(new Container);
        app()->instance(ManifestCollectionSource::class, new class implements ManifestCollectionSource
        {
            public function collections(BeamUxEntry $entry, string $profile): array
            {
                return [new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']),
                    new CollectionSource('other', 'records', ['collection' => 'https://tenant.test/other'])];
            }
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
    }

    private function service(): BindingFieldEvolution
    {
        $registry = new class implements SchemaRegistry
        {
            private array $schemas = [
                'https://tenant.test/articles/1' => ['$id' => 'https://tenant.test/articles/1', 'properties' => [
                    'title' => ['type' => 'string', 'x-filter' => ['name' => 'heading'], 'x-sort' => ['name' => 'heading-order']],
                    'dropped' => ['type' => 'string', 'x-filter' => ['name' => 'obsolete']],
                ]],
                'https://tenant.test/articles/2' => ['$id' => 'https://tenant.test/articles/2', 'properties' => [
                    'name' => ['type' => 'string', 'x-migrate-from' => 'title', 'x-filter' => ['name' => 'name-filter'], 'x-sort' => ['name' => 'name-order']],
                ]],
            ];

            public function register(array $schema): void
            {
                throw new \LogicException('Read projection cannot register.');
            }

            public function ids(): array
            {
                return array_keys($this->schemas);
            }

            public function get(string $id): ?array
            {
                return $this->schemas[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->schemas[$id]);
            }
        };

        return new BindingFieldEvolution(new ProfileCollections, $registry);
    }

    public function test_real_document_projection_changes_only_references_in_the_declared_source_scope(): void
    {
        $entry = $this->createMock(BeamUxEntry::class);
        $entry->method('getAttribute')->with('record_page')->willReturn(null);
        $body = [
            ['kind' => 'block', 'name' => 'div', 'id' => 'articles', 'repeat' => ['collection' => 'articles', 'query' => ['filter' => ['heading' => 'title'], 'sort' => '-heading-order']],
                'props' => [['kind' => 'literal', 'name' => 'title', 'value' => 'fields.title']],
                'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title'], ['kind' => 'text', 'value' => '', 'field' => 'fields.dropped']],
                'slots' => ['caption' => [['kind' => 'block', 'name' => 'span', 'props' => [['kind' => 'field', 'name' => 'title', 'value' => 'fields.title']],
                    'when' => ['all' => [['field' => 'fields.title', 'operator' => 'filled', 'value' => 'title']]]]]]],
            ['kind' => 'block', 'name' => 'div', 'id' => 'other', 'repeat' => ['collection' => 'other'], 'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title']]],
        ];
        $actual = $this->service()->document($entry, $body);
        self::assertSame('fields.name', $actual[0]['children'][0]['field']);
        self::assertSame('fields.dropped', $actual[0]['children'][1]['field']);
        self::assertSame('fields.name', $actual[0]['slots']['caption'][0]['props'][0]['value']);
        self::assertSame('fields.name', $actual[0]['slots']['caption'][0]['when']['all'][0]['field']);
        self::assertSame('title', $actual[0]['slots']['caption'][0]['when']['all'][0]['value']);
        self::assertSame(['filter' => ['name-filter' => 'title'], 'sort' => '-name-order'], $actual[0]['repeat']['query']);
        self::assertSame($body[0]['props'], $actual[0]['props']);
        self::assertSame($body[1], $actual[1]);
        self::assertSame('fields.title', $body[0]['children'][0]['field']);
    }

    public function test_page_fields_follow_declared_renames_drop_only_known_drops_and_retain_unknown_input_for_validation(): void
    {
        self::assertSame(['name' => 'old value', 'unknown' => 'do not hide'], $this->service()->pageFields('https://tenant.test/articles', ['title' => 'old value', 'dropped' => 'gone', 'unknown' => 'do not hide'], true));
        self::assertSame(['title' => 'unrelated'], $this->service()->pageFields('https://tenant.test/other', ['title' => 'unrelated']));
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->pageFields('https://tenant.test/articles', ['title' => 'old', 'name' => 'different']);
    }

    public function test_frozen_compiled_references_receive_current_values_without_mutating_the_published_body(): void
    {
        $service = $this->service();
        app()->instance(BindingFieldEvolution::class, $service);
        $resolver = new \Splicewire\Beam\Ux\Bindings\BindingResolver(new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument, new ProfileCollections);
        $source = new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']);
        $frozen = [['kind' => 'text', 'value' => '', 'field' => 'fields.title'], ['kind' => 'text', 'value' => '', 'field' => 'fields.dropped']];
        $row = ['fields' => ['name' => 'current value', 'dropped' => 'stale/private value', 'secret' => 'unreferenced']];
        self::assertSame(['fields' => ['title' => 'current value']], $resolver->projectRoot($frozen, $row, source: $source));
        self::assertSame('fields.title', $frozen[0]['field']);
        self::assertNull($service->fieldPath($source, 'fields.dropped'));
        self::assertSame('id', $service->fieldPath($source, 'id'));
        self::assertSame(['filter' => ['name-filter' => 'literal title']], $service->query($source, ['filter' => ['heading' => 'literal title']]));
    }

    public function test_actual_repeat_reader_uses_empty_state_for_a_known_dropped_saved_query_before_any_resource_read(): void
    {
        app()->instance(BindingFieldEvolution::class, $this->service());
        $resolver = new \Splicewire\Beam\Ux\Bindings\BindingResolver(new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument, new ProfileCollections);
        $entry = $this->createStub(BeamUxEntry::class);
        $node = ['id' => 'repeated', 'repeat' => ['collection' => 'articles', 'query' => ['filter' => ['obsolete' => 'literal'], 'limit' => 7]]];
        $result = (new \ReflectionMethod($resolver, 'records'))->invoke($resolver, $node, $entry, \Illuminate\Http\Request::create('/?repeat_repeated=2'), 'tenant');
        self::assertSame([], $result->records);
        self::assertFalse($result->hasMore);
        self::assertSame(2, $result->page);
        // An unknown alias is never misclassified as a known drop or silently removed.
        self::assertSame(['filter' => ['unknown' => 'literal']], $this->service()->query(new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']), ['filter' => ['unknown' => 'literal']]));
    }

    public function test_actual_record_page_url_keeps_its_frozen_placeholder_but_uses_the_renamed_unique_field(): void
    {
        app()->instance(BindingFieldEvolution::class, $this->service());
        $page = $this->createStub(BeamUxEntry::class);
        $page->method('getAttribute')->willReturnCallback(fn ($key) => $key === 'record_page' ? ['collection' => 'articles', 'field' => 'title'] : null);
        $page->method('url')->willReturn('/articles/{title}');
        $pages = new \Splicewire\Beam\Ux\Bindings\RecordPages(new ProfileCollections);
        self::assertSame('/articles/reader-value', $pages->url($page, ['fields' => ['name' => 'reader-value']]));
        self::assertNull($pages->url($page, ['fields' => ['name' => '../unsafe']]));
    }

    public function test_published_binding_document_reads_only_the_pin_and_keeps_its_snapshot_unmodified(): void
    {
        $particle = $this->createStub(\Splicewire\Beam\Models\BeamParticle::class);
        $entry = $this->createStub(BeamUxEntry::class);
        $entry->method('getAttribute')->willReturnCallback(fn ($key) => $key === 'published_version' ? 'published-pin' : null);
        $entry->method('__get')->willReturnCallback(fn ($key) => match ($key) {
            'particle_id' => 'particle-a', 'particle' => $particle, default => null
        });
        $entry->method('versionable')->willReturn($particle);
        $snapshot = ['payload' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title']], 'meta' => ['pageSettings' => ['fields' => ['title' => 'published value']]]];
        $version = $this->createStub(\Rushing\Versioning\Models\Version::class);
        $version->method('__get')->willReturnCallback(fn ($key) => $key === 'snapshot' ? $snapshot : null);
        $version->method('__isset')->willReturn(true);
        $store = \Mockery::mock(\Rushing\Versioning\Contracts\VersionStore::class);
        $store->shouldReceive('version')->twice()->with($particle, 'published-pin')->andReturn($version);
        $store->shouldNotReceive('head', 'snapshot', 'restore');
        app()->instance(\Rushing\Versioning\Contracts\VersionStore::class, $store);
        try {
            $documents = new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument;
            self::assertSame($snapshot['payload'], $documents->read($entry));
            self::assertSame($snapshot['meta']['pageSettings'], $documents->pageSettings($entry));
            self::assertSame('fields.title', $snapshot['payload'][0]['field']);
        } finally {
            \Mockery::close();
        }
    }

    public function test_native_version_enumerator_avoids_a_scan_of_unrelated_schema_identities(): void
    {
        $registry = new class implements \Schemastud\DataSchemas\Contracts\EnumeratesVersions, SchemaRegistry
        {
            public function register(array $schema): void
            {
                throw new \LogicException('Read only');
            }

            public function ids(): array
            {
                throw new \LogicException('Must use the exact version enumeration capability');
            }

            public function versionsFor(string $stem): array
            {
                return [1];
            }

            public function get(string $id): ?array
            {
                return ['$id' => $id, 'properties' => ['title' => ['type' => 'string']]];
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        self::assertSame(['title' => 'preserved'], (new BindingFieldEvolution(new ProfileCollections, $registry))->pageFields('https://tenant.test/articles', ['title' => 'preserved']));
    }
}
