<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Ux\Bindings\BindingFieldEvolution;
use Splicewire\Beam\Ux\Bindings\BindingFieldValidator;
use Splicewire\Beam\Ux\Bindings\BindingNodeBaseline;
use Splicewire\Beam\Ux\Bindings\CollectionSource;
use Splicewire\Beam\Ux\Bindings\ProfileCollections;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

final class DroppedBindingAdmissionTest extends TestCase
{
    private mixed $previousFacade;

    private \Illuminate\Container\Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = \Illuminate\Container\Container::getInstance();
        $this->previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $container = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $dataConfig = require dirname((new \ReflectionClass(\Spatie\LaravelData\Data::class))->getFileName(), 2).'/config/data.php';
        $container->instance('config', new \Illuminate\Config\Repository(['data' => $dataConfig]));
        \Illuminate\Container\Container::setInstance($container);
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
        $container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('validator');
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('validator');
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->previousFacade);
        \Illuminate\Container\Container::setInstance($this->previousContainer);
    }

    private function evolution(): BindingFieldEvolution
    {
        return new BindingFieldEvolution(new ProfileCollections, new class implements SchemaRegistry
        {
            public function ids(): array
            {
                return ['https://tenant.test/articles/1', 'https://tenant.test/articles/2'];
            }

            public function register(array $schema): void
            {
                throw new \LogicException('No writes.');
            }

            public function has(string $id): bool
            {
                return in_array($id, $this->ids(), true);
            }

            public function get(string $id): ?array
            {
                $schema = DroppedBindingAdmissionTest::currentFields();
                $schema['$id'] = $id;
                if (str_ends_with($id, '/1')) {
                    $schema['properties'] += [
                        'old' => ['type' => 'string', 'x-filter' => ['name' => 'retired']],
                        'rich' => ['type' => 'string', 'format' => 'mdx'],
                        'object' => ['type' => 'object', 'properties' => ['child' => ['type' => 'string']]],
                    ];
                }

                return $schema;
            }
        });
    }

    public static function currentFields(): array
    {
        return ['$id' => 'https://tenant.test/articles/2', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'slug'],
            'properties' => ['slug' => ['type' => 'string']]];
    }

    private function body(): array
    {
        return [['kind' => 'block', 'name' => 'div', 'id' => 'repeat', 'repeat' => ['collection' => 'articles'],
            'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.old']]],
            ['kind' => 'text', 'value' => 'unrelated']];
    }

    private function check(array $body, array $previous): void
    {
        $baseline = new BindingNodeBaseline($previous);
        $source = new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']);
        $scope = ['name' => 'articles', 'source' => $source, 'schema' => ['properties' => ['fields' => self::currentFields()]]];
        $evolution = $this->evolution();
        $entry = new BeamUxEntry;
        (new BindingFieldValidator)->assert($body, ['x-collections' => [$scope]], null,
            fn ($path, $scope, $node, $at, $context) => $baseline->unchanged($node, $at, $context)
                ? $evolution->droppedScope($entry, $scope, $path) : null);
    }

    public function test_unrelated_edit_keeps_identical_idless_dropped_text_and_nested_declared_field(): void
    {
        $previous = $this->body();
        $body = $previous;
        $body[1]['value'] = 'edited';
        $this->check($body, $previous);
        $previous[0]['children'][0]['field'] = 'fields.object.child';
        $body = $previous;
        $body[1]['value'] = 'edited again';
        $this->check($body, $previous);
        $body[0] = array_reverse($body[0], true);
        $body[0]['children'][0] = array_reverse($body[0]['children'][0], true);
        $this->check($body, $previous);
        self::assertSame('fields.old', $this->body()[0]['children'][0]['field']);
    }

    public function test_unchanged_is_not_enough_for_unknown_or_changed_or_moved_or_rescoped_field(): void
    {
        $previous = $this->body();
        $cases = [];
        $unknown = $previous;
        $unknown[0]['children'][0]['field'] = 'fields.neverDeclared';
        $cases[] = [$unknown, $unknown];
        $changed = $previous;
        $changed[0]['children'][0]['value'] = 'changed dropped node';
        $cases[] = [$changed, $previous];
        $moved = $previous;
        array_unshift($moved[0]['children'], ['kind' => 'text', 'value' => 'new sibling']);
        $cases[] = [$moved, $previous];
        $rescoped = $previous;
        $rescoped[0]['repeat']['query'] = ['limit' => 1];
        $cases[] = [$rescoped, $previous];
        $slot = $previous;
        $slot[0]['slots']['caption'] = $slot[0]['children'];
        $slot[0]['children'] = [];
        $cases[] = [$slot, $previous];
        foreach ($cases as [$body, $before]) {
            try {
                $this->check($body, $before);
                self::fail('An unknown, changed or differently scoped drop must fail.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('body', $e->errors());
            }
        }
    }

    public function test_historical_contract_still_refuses_rich_text_and_executable_attribute(): void
    {
        foreach (['text', 'attribute'] as $case) {
            $body = $this->body();
            if ($case === 'text') {
                $body[0]['children'][0]['field'] = 'fields.rich';
            } else {
                $body[0]['children'][0] = ['kind' => 'block', 'name' => 'span',
                    'props' => [['kind' => 'field', 'name' => 'onClick', 'value' => 'fields.old']]];
            }
            try {
                $this->check($body, $body);
                self::fail('Historical declarations cannot waive rich-field or sink rules.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('body', $e->errors());
            }
        }
    }

    public function test_empty_scope_and_slot_paths_do_not_alias_children_or_inner_repeat_scope(): void
    {
        $body = $this->body();
        $body[0]['repeat']['empty'] = $body[0]['children'];
        $this->expectException(ValidationException::class);
        $this->check($body, $body);
    }

    public function test_read_empty_is_only_for_the_typed_absent_offer_and_author_require_stays_422(): void
    {
        $gate = \Mockery::mock(\Illuminate\Contracts\Auth\Access\Gate::class);
        $gate->shouldReceive('forUser')->andReturnSelf();
        app()->instance(\Illuminate\Contracts\Auth\Access\Gate::class, $gate);
        app()->instance(\Splicewire\Beam\Ux\Bindings\ManifestCollectionSource::class,
            new class implements \Splicewire\Beam\Ux\Bindings\ManifestCollectionSource
            {
                public function collections(BeamUxEntry $entry, string $profile): array
                {
                    return [];
                }
            });
        $profiles = new ProfileCollections;
        $entry = new BeamUxEntry;
        $resolver = new \Splicewire\Beam\Ux\Bindings\BindingResolver(new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument, $profiles);
        $result = $resolver->resolveDocument($entry, $this->body(), new \Illuminate\Http\Request, 'site');
        self::assertSame([], $result['repeat']->records);
        self::assertFalse($result['repeat']->hasMore);
        try {
            $profiles->require($entry, 'articles');
            self::fail('Author admission must still refuse an absent Collection.');
        } catch (\Splicewire\Beam\Ux\Bindings\MissingProfileCollection $error) {
            self::assertSame('articles', $error->collection);
            self::assertSame(422, $error->status);
        }
        app()->instance(\Splicewire\Beam\Ux\Bindings\ManifestCollectionSource::class,
            new class implements \Splicewire\Beam\Ux\Bindings\ManifestCollectionSource
            {
                public function collections(BeamUxEntry $entry, string $profile): array
                {
                    throw new \RuntimeException('registry failed');
                }
            });
        $this->expectExceptionMessage('registry failed');
        $resolver->resolveDocument($entry, $this->body(), new \Illuminate\Http\Request, 'site');
    }

    public function test_diagnostics_report_actual_drops_and_missing_sources_without_mutating_or_inventing_scope(): void
    {
        $source = new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']);
        $scope = ['name' => 'articles', 'source' => $source, 'schema' => ['properties' => ['fields' => self::currentFields()]]];
        $body = $this->body();
        $body[0]['slots']['caption'] = [['kind' => 'text', 'value' => '', 'field' => 'fields.object.child']];
        $body[0]['repeat']['empty'] = [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'held'],
            'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.old']]]];
        $before = $body;
        $diagnostics = new \Splicewire\Beam\Ux\Bindings\DroppedBindingDiagnostics(new BindingFieldValidator, $this->evolution());
        self::assertSame([
            '/0/repeat/empty/0' => ['Collection held is unavailable'],
            '/0/children/0' => ['fields.old'],
            '/0/slots/caption/0' => ['fields.object.child'],
        ], $diagnostics->inspect(new BeamUxEntry, $body, ['x-collections' => [$scope]]));
        self::assertSame($before, $body);
    }

    public function test_query_validation_prunes_only_known_drops_and_keeps_unknowns_variants_and_values(): void
    {
        $old = ['$id' => 'https://tenant.test/articles/1', 'properties' => [
            'old' => ['type' => 'string', 'x-filter' => ['name' => 'retired'], 'x-sort' => ['name' => 'retired-order']],
            'title' => ['type' => 'string', 'x-filter' => ['name' => 'heading']],
        ]];
        $new = ['$id' => 'https://tenant.test/articles/2', 'properties' => [
            'name' => ['type' => 'string', 'x-migrate-from' => 'title', 'x-filter' => ['name' => 'name-filter']],
        ]];
        $evolution = new \Splicewire\Beam\Ux\Bindings\DeclaredFieldRenames([$old, $new]);
        $query = ['filter' => ['retired' => 'old', 'heading' => 'literal', 'unknown' => 'do not hide'],
            'sort' => '-retired-order,--retired-order,unknown'];
        self::assertSame(['filter' => ['name-filter' => 'literal', 'unknown' => 'do not hide'],
            'sort' => '--retired-order,unknown'], $evolution->queryForValidation($query));
        self::assertSame(['filter.retired', 'sort.retired-order'], $evolution->droppedQueryFields($query));
        self::assertSame('old', $query['filter']['retired']);
        $variant = [...$query, 'filterVariant' => 'separate-vocabulary'];
        self::assertSame($variant, $evolution->queryForValidation($variant));
        self::assertSame([], $evolution->droppedQueryFields($variant));
    }

    public function test_real_save_guard_uses_the_baseline_only_for_untouched_known_drops(): void
    {
        $source = new CollectionSource('articles', 'records', ['collection' => 'https://tenant.test/articles']);
        app()->instance(\Splicewire\Beam\Ux\Bindings\ManifestCollectionSource::class,
            new class($source) implements \Splicewire\Beam\Ux\Bindings\ManifestCollectionSource
            {
                public function __construct(private CollectionSource $source) {}

                public function collections(BeamUxEntry $entry, string $profile): array
                {
                    return [$this->source];
                }
            });
        $resources = \Mockery::mock(\Splicewire\Beam\Particle\ParticleResourceRegistry::class);
        $resources->shouldReceive('get')->with('records')->andReturn(new \Splicewire\Beam\Particle\ParticleResource('records', \Splicewire\Beam\Collections\CollectionBacking::class));
        app()->instance(\Splicewire\Beam\Particle\ParticleResourceRegistry::class, $resources);
        $targets = new class implements \Splicewire\Beam\Schema\Contracts\SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                return DroppedBindingAdmissionTest::currentFields();
            }
        };
        $registry = \Mockery::mock(SchemaRegistry::class);
        app()->instance(\Splicewire\Beam\Collections\CollectionSchemaResolver::class, new \Splicewire\Beam\Collections\CollectionSchemaResolver($targets, $registry));
        app()->instance(BindingFieldEvolution::class, $this->evolution());
        $connection = \Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $entry = $this->createMock(BeamUxEntry::class);
        $entry->method('getConnection')->willReturn($connection);
        $entry->method('getAttribute')->with('record_page')->willReturn(null);
        $guard = new \Splicewire\Beam\Ux\Bindings\BindingBodyGuard(new ProfileCollections, new BindingFieldValidator);
        $before = $this->body();
        $body = $before;
        $body[1]['value'] = 'unrelated edit';
        $profile = ['x-collections' => [['name' => 'articles']]];
        $guard->assert($entry, $body, $profile, $before);
        self::assertSame('fields.old', $body[0]['children'][0]['field']);
        $owner = new class extends \Splicewire\Beam\Filters\FilterQuerySelection
        {
            public array $seen = [];

            public bool $denied = false;

            public function __construct() {}

            public function validateParticle(string $target, array $parameters): array
            {
                $this->seen[] = [$target, $parameters];
                if ($this->denied) {
                    throw new \Illuminate\Auth\Access\AuthorizationException('owner denied');
                }
                if (($parameters['filter'] ?? []) !== []) {
                    throw ValidationException::withMessages(['query' => 'owner unknown filter']);
                }

                return $parameters;
            }
        };
        app()->instance(\Splicewire\Beam\Filters\FilterQuerySelection::class, $owner);
        app()->instance(\Illuminate\Http\Request::class, new \Illuminate\Http\Request);
        $queryBefore = $before;
        $queryBefore[0]['repeat']['query'] = ['filter' => ['retired' => 'literal']];
        $queryBody = $queryBefore;
        $queryBody[1]['value'] = 'unrelated';
        $guard->assert($entry, $queryBody, $profile, $queryBefore);
        self::assertSame([['records', ['filter' => []]]], $owner->seen);
        self::assertSame('literal', $queryBody[0]['repeat']['query']['filter']['retired']);
        $owner->denied = true;
        try {
            $guard->assert($entry, $queryBody, $profile, $queryBefore);
            self::fail('An unchanged query cannot waive the owner access refusal.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $error) {
            self::assertSame('owner denied', $error->getMessage());
        }
        $owner->denied = false;
        $queryBefore[0]['repeat']['query']['filter']['unknown'] = 'not a drop';
        try {
            $guard->assert($entry, $queryBefore, $profile, $queryBefore);
            self::fail('An unknown historical query alias cannot be hidden.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('query', $error->errors());
        }
        $rootEntry = $this->createMock(BeamUxEntry::class);
        $rootEntry->method('getConnection')->willReturn($connection);
        $rootEntry->method('getAttribute')->with('record_page')->willReturn(['collection' => 'articles']);
        $rootBody = [['kind' => 'text', 'value' => '', 'field' => 'fields.old']];
        $guard->assert($rootEntry, $rootBody, $profile, $rootBody, ['collection' => 'articles']);
        try {
            $guard->assert($rootEntry, $rootBody, $profile, $rootBody, ['collection' => 'different-root']);
            self::fail('A changed Record Page root must not borrow the old node baseline.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('body', $error->errors());
        }
        $this->expectException(ValidationException::class);
        $guard->assert($entry, $body, $profile); // No proof of the previous accepted node: refuse.
    }

    public function test_doctor_without_a_registry_reports_unexamined_instead_of_a_clean_bill(): void
    {
        $evolution = new BindingFieldEvolution(new ProfileCollections);
        $guard = (new \ReflectionClass(\Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard::class))->newInstanceWithoutConstructor();
        $audit = new \Splicewire\Beam\Ux\Doctor\DroppedBindingsAudit($evolution,
            new \Splicewire\Beam\Ux\Bindings\DroppedBindingDiagnostics(new BindingFieldValidator, $evolution),
            $guard, new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument);
        $findings = $audit->run();
        self::assertFalse($findings[0]->conclusive);
        self::assertStringContainsString('no bodies examined', $findings[0]->detail);
    }

    public function test_working_root_baseline_uses_stored_metadata_or_persisted_original_not_prospective_settings(): void
    {
        $entry = new BeamUxEntry;
        $entry->setRawAttributes(['record_page' => json_encode(['collection' => 'persisted'])], true);
        $entry->setRelation('particle', null);
        $entry->setAttribute('record_page', ['collection' => 'prospective']);
        $guard = (new \ReflectionClass(\Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard::class))->newInstanceWithoutConstructor();
        self::assertSame(['collection' => 'persisted'], $guard->workingRecordPage($entry));
        $particle = new \Splicewire\Beam\Models\BeamParticle;
        $particle->meta = ['pageSettings' => ['recordPage' => null]];
        $entry->setRelation('particle', $particle);
        self::assertNull($guard->workingRecordPage($entry));
        $particle->meta = ['pageSettings' => ['recordPage' => ['collection' => 'stored-working']]];
        self::assertSame(['collection' => 'stored-working'], $guard->workingRecordPage($entry));
    }
}
