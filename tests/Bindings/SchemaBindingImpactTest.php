<?php

namespace Splicewire\Beam\Ux\Tests\Bindings;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Schema\SchemaRegistrationConfirmationData;
use Splicewire\Beam\Ux\Bindings\SchemaBindingImpact;

final class SchemaBindingImpactTest extends TestCase
{
    private mixed $previousFacade;

    private \Illuminate\Container\Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = \Illuminate\Container\Container::getInstance();
        $this->previousFacade = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $container = new \Illuminate\Container\Container;
        \Illuminate\Container\Container::setInstance($container);
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
        $container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('validator');
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('validator');
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->previousFacade);
        \Illuminate\Container\Container::setInstance($this->previousContainer);
    }

    private string $stem = 'https://tenant.test/schemas/articles';

    private function old(): array
    {
        return ['$id' => $this->stem.'/1', 'type' => 'object', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'slug'], 'properties' => [
            'slug' => ['type' => 'string', 'x-beam-unique' => true],
            'title' => ['type' => 'string', 'x-filter' => ['name' => 'heading'], 'x-sort' => ['name' => 'heading-order']],
        ]];
    }

    private function body(): array
    {
        return [['kind' => 'block', 'name' => 'div', 'repeat' => ['collection' => 'articles', 'query' => ['filter' => ['heading' => 'literal'], 'sort' => '-heading-order']],
            'children' => [['kind' => 'text', 'value' => '', 'field' => 'fields.title']],
            'slots' => ['caption' => [['kind' => 'block', 'name' => 'span', 'props' => [['kind' => 'field', 'name' => 'title', 'value' => 'fields.title']],
                'when' => ['all' => [['field' => 'fields.title', 'operator' => 'filled']]]]]]]];
    }

    public function test_lists_actual_dropped_references_and_keeps_other_schema_scopes_out(): void
    {
        $new = ['$id' => $this->stem.'/2', 'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true]]];
        $impact = new SchemaBindingImpact;
        self::assertSame(['title'], $impact->fields([$this->old()], $new, $this->body(), ['articles' => $this->stem], null));
        self::assertSame([], $impact->fields([$this->old()], $new, $this->body(), ['articles' => 'https://other.test/schemas/articles'], null));
        $new['properties']['name'] = ['type' => 'string', 'x-migrate-from' => 'title', 'x-filter' => ['name' => 'name-filter'], 'x-sort' => ['name' => 'name-order']];
        self::assertSame([], $impact->fields([$this->old()], $new, $this->body(), ['articles' => $this->stem], null));
    }

    public function test_address_break_is_unconditionally_refused_even_without_any_record_migration(): void
    {
        $old = $this->old();
        foreach ([[], ['type' => 'string'], ['type' => 'integer', 'x-beam-unique' => true]] as $property) {
            $new = ['$id' => $this->stem.'/2', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'title'], 'properties' => ['title' => ['type' => 'string']]];
            if ($property !== []) {
                $new['properties']['slug'] = $property;
            }
            try {
                (new SchemaBindingImpact)->fields([$old], $new, [], ['articles' => $this->stem], ['collection' => 'articles', 'field' => 'slug']);
                self::fail('A published address cannot be invalidated by schema registration.');
            } catch (ValidationException $e) {
                self::assertStringContainsString('Rebind the published Record Page', $e->errors()['schema'][0]);
            }
        }
    }

    public function test_declared_address_rename_keeps_unique_string_contract(): void
    {
        $old = $this->old();
        $new = ['$id' => $this->stem.'/2', 'type' => 'object', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'address'], 'properties' => ['address' => ['type' => 'string', 'x-beam-unique' => true, 'x-migrate-from' => 'slug']]];
        self::assertSame([], (new SchemaBindingImpact)->fields([$old], $new, [], ['articles' => $this->stem], ['collection' => 'articles', 'field' => 'slug']));
    }

    public function test_confirmation_is_bound_to_full_proposed_artifact_and_exact_published_pins(): void
    {
        $schema = $this->old();
        $confirmation = new SchemaRegistrationConfirmationData(\Schemastud\DataSchemas\Lifecycle\SchemaFingerprint::of($schema, []), ['page-a' => 'v1']);
        self::assertTrue($confirmation->matches($schema, ['page-a' => 'v1']));
        self::assertFalse($confirmation->matches($schema, ['page-a' => 'v2']));
        self::assertFalse($confirmation->matches($schema, ['page-b' => 'v1']));
        $schema['$id'] = $this->stem.'/2';
        self::assertFalse($confirmation->matches($schema, ['page-a' => 'v1']));
    }

    private function registry(?array $schemas = null): \Schemastud\DataSchemas\Contracts\SchemaRegistry
    {
        return new class($schemas ?? [$this->old()]) implements \Schemastud\DataSchemas\Contracts\SchemaRegistry
        {
            public function __construct(private array $schemas) {}

            public function register(array $schema): void
            {
                throw new \LogicException('Read-only declaration context');
            }

            public function ids(): array
            {
                return array_column($this->schemas, '$id');
            }

            public function get(string $id): ?array
            {
                foreach ($this->schemas as $schema) {
                    if ($schema['$id'] === $id) {
                        return $schema;
                    }
                }

return null;
            }

            public function has(string $id): bool
            {
                return $this->get($id) !== null;
            }
        };
    }

    private function guard(?array $recordPage = null): \Splicewire\Beam\Ux\Bindings\GuardSchemaRegistration
    {
        app()->instance(\Splicewire\Beam\Ux\Bindings\ManifestCollectionSource::class, new class implements \Splicewire\Beam\Ux\Bindings\ManifestCollectionSource
        {
            public function collections(\Splicewire\Beam\Ux\Models\BeamUxEntry $entry, string $profile): array
            {
                return []; // No actor is entitled to the editor manifest in this queue-context control.
            }
        });
        $page = $this->createStub(\Splicewire\Beam\Ux\Models\BeamUxEntry::class);
        $page->method('getKey')->willReturn('page-a');
        $attributes = fn ($key) => match ($key) {
            'record_page' => $recordPage, 'published_version' => 'pin-1', 'title' => 'Published Page', default => null,
        };
        $page->method('getAttribute')->willReturnCallback($attributes);
        $page->method('__get')->willReturnCallback($attributes);
        $documents = new class($this->body()) extends \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument
        {
            public function __construct(private array $body) {}

            public function read(\Splicewire\Beam\Ux\Models\BeamUxEntry $entry): array
            {
                return $this->body;
            }
        };

        return new class($page, $documents) extends \Splicewire\Beam\Ux\Bindings\GuardSchemaRegistration
        {
            public function __construct(private \Splicewire\Beam\Ux\Models\BeamUxEntry $page, \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument $documents)
            {
                parent::__construct($documents, new SchemaBindingImpact);
            }

            protected function publishedPages(\Splicewire\Beam\Schema\SchemaVersionRegistering $event): iterable
            {
                return [$this->page];
            }
        };
    }

    public function test_actual_listener_reports_before_freeze_accepts_only_matching_receipt_and_rechecks_stale_pins(): void
    {
        $schema = ['$id' => $this->stem.'/2', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'slug'], 'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true]]];
        $guard = $this->guard();
        try {
            $guard(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [$this->old()], $this->registry(), 'tenant'));
            self::fail('An affected published page must be reported before registration.');
        } catch (\Splicewire\Beam\Ux\Bindings\SchemaVersionImpactRequired $e) {
            self::assertSame('Published Page', $e->impact->pages[0]->title);
            self::assertSame(['title'], $e->impact->pages[0]->fields);
            self::assertSame(['page-a' => 'pin-1'], $e->impact->publishedVersions());
            $receipt = new SchemaRegistrationConfirmationData($e->impact->schemaFingerprint, $e->impact->publishedVersions());
        }
        $guard(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [$this->old()], $this->registry(), 'tenant', $receipt));
        $receipt->publishedVersions['page-a'] = 'stale-pin';
        $this->expectException(\Splicewire\Beam\Ux\Bindings\SchemaVersionImpactRequired::class);
        $guard(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [$this->old()], $this->registry(), 'tenant', $receipt));
    }

    public function test_actual_listener_never_waives_a_published_address_break_with_confirmation(): void
    {
        $schema = ['$id' => $this->stem.'/2', 'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'title'], 'properties' => ['title' => ['type' => 'string']]];
        $receipt = new SchemaRegistrationConfirmationData(\Schemastud\DataSchemas\Lifecycle\SchemaFingerprint::of($schema, []), ['page-a' => 'pin-1']);
        $this->expectException(ValidationException::class);
        ($this->guard(['collection' => 'articles', 'field' => 'slug']))(
            new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [$this->old()], $this->registry(), 'tenant', $receipt));
    }

    public function test_new_cross_stem_alias_collision_refuses_with_published_page_identity_even_with_confirmation(): void
    {
        $schema = $this->old();
        $schema['$id'] = 'https://tenant.test/schemas/new/articles/1';
        $receipt = new SchemaRegistrationConfirmationData(\Schemastud\DataSchemas\Lifecycle\SchemaFingerprint::of($schema, []), ['page-a' => 'pin-1']);
        try {
            ($this->guard())(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [], $this->registry(), 'tenant', $receipt));
            self::fail("A new Collection must not rename a published page's existing alias.");
        } catch (ValidationException $e) {
            self::assertStringContainsString('Published Page / page-a', $e->errors()['schema.aliases'][0]);
            self::assertStringContainsString('[articles]', $e->errors()['schema.aliases'][0]);
        }
    }

    public function test_a_fresh_registry_without_published_bindings_can_use_the_final_collision_aliases(): void
    {
        $schema = $this->old();
        $schema['$id'] = 'https://tenant.test/schemas/new/articles/1';
        $guard = new class(new \Splicewire\Beam\Ux\Bindings\PublishedBindingDocument, new SchemaBindingImpact) extends \Splicewire\Beam\Ux\Bindings\GuardSchemaRegistration
        {
            protected function publishedPages(\Splicewire\Beam\Schema\SchemaVersionRegistering $event): iterable
            {
                return [];
            }
        };
        $guard(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [], $this->registry(), 'tenant'));
        $this->addToAssertionCount(1);
    }

    public function test_an_already_broken_bare_alias_is_held_instead_of_guessing_a_stem(): void
    {
        $other = $this->old();
        $other['$id'] = 'https://tenant.test/schemas/other/articles/1';
        $schema = $this->old();
        $schema['$id'] = $this->stem.'/2';
        try {
            ($this->guard())(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [$this->old()], $this->registry([$this->old(), $other]), 'tenant'));
            self::fail('An ambiguous historical alias must be held.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('ambiguous [articles]', $e->errors()['schema.aliases'][0]);
        }
    }

    public function test_unmarked_legacy_data_class_registration_does_not_borrow_a_collection_alias_or_require_review(): void
    {
        $other = $this->old();
        $other['$id'] = 'https://tenant.test/schemas/other/articles/1';
        $schema = ['$id' => 'https://tenant.test/schemas/legacy/articles/1', 'type' => 'object', 'properties' => ['legacy' => ['type' => 'string']]];
        ($this->guard())(new \Splicewire\Beam\Schema\SchemaVersionRegistering($schema, [], $this->registry([$this->old(), $other]), 'tenant'));
        $this->addToAssertionCount(1);
    }
}
