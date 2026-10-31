<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Ux\Containment\PageFields;
use Splicewire\Beam\Ux\Containment\RegisteredPageFields;
use Splicewire\Beam\Ux\Tests\Fixtures\RecipeFields;

/** No database or host fixtures: exercise the actual registry/schema/save/frontmatter path. */
final class RegisteredPageFieldsTest extends TestCase
{
    private Container $previous;

    private const STEM = 'https://tenant.test/schemas/recipe';

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
        $container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
        $container->instance('config', new Repository(['beam' => ['ux' => ['page_fields' => ['legacy' => RecipeFields::class]]]]));
        $registry = new class implements SchemaRegistry
        {
            public array $schemas = [];

            public function register(array $schema): void
            {
                $this->schemas[$schema['$id']] = $schema;
            }

            public function get(string $id): ?array
            {
                return $this->schemas[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->schemas[$id]);
            }

            public function ids(): array
            {
                return array_keys($this->schemas);
            }
        };
        $container->instance(SchemaRegistry::class, $registry);
        $container->instance(RegisteredPageFields::class, new RegisteredPageFields($registry));
        $registry->register(['$id' => self::STEM.'/1', 'type' => 'object', 'properties' => ['obsolete' => ['type' => 'string']]]);
        // No x-beam-collection marker: Page Fields do not require a Collection.
        $registry->register(['$id' => self::STEM.'/2', 'type' => 'object', 'required' => ['servings'], 'properties' => [
            'servings' => ['type' => 'integer', 'minimum' => 1],
            'vegetarian' => ['type' => ['boolean', 'null']],
        ]]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->previous);
        parent::tearDown();
    }

    public function test_registered_stem_uses_latest_schema_for_offer_save_and_frontmatter(): void
    {
        self::assertSame(self::STEM, PageFields::named(self::STEM));
        self::assertContains('legacy', PageFields::names());
        self::assertContains(self::STEM, PageFields::names());
        self::assertSame(self::STEM.'/2', PageFields::schema(self::STEM)['$id']);
        [$fields, $rest] = PageFields::fromFrontmatter(self::STEM, ['servings' => '4', 'vegetarian' => 'false', 'other' => 'kept']);
        self::assertSame(['servings' => 4, 'vegetarian' => false], PageFields::check(self::STEM, $fields));
        self::assertSame(['other' => 'kept'], $rest);
        self::assertSame(['servings' => '4', 'vegetarian' => 'false'], PageFields::toFrontmatter($fields));
        self::assertSame(RecipeFields::class, PageFields::named('legacy'));
        self::assertSame([['servings' => 3, 'vegetarian' => true], []], PageFields::fromFrontmatter(RecipeFields::class, ['servings' => '3', 'vegetarian' => 'true']));
    }

    public function test_registered_values_obey_schema_constraints(): void
    {
        $this->expectException(ValidationException::class);
        PageFields::check(self::STEM, ['servings' => 0]);
    }

    public function test_registered_schema_rejects_undeclared_fields(): void
    {
        $this->expectException(ValidationException::class);
        PageFields::check(self::STEM, ['servings' => 2, 'obsolete' => 'gone']);
    }

    public function test_registered_fields_cannot_be_kept_as_unclaimed(): void
    {
        $this->expectException(ValidationException::class);
        PageFields::assertUnclaimed(self::STEM, ['servings' => '2']);
    }

    public function test_unbound_registry_does_not_offer_composite_or_central_schemas(): void
    {
        app()->forgetInstance(RegisteredPageFields::class);
        self::assertNull(PageFields::named(self::STEM));
        self::assertSame(['legacy'], PageFields::names());
    }

    public function test_inactive_tenant_does_not_offer_registered_schemas(): void
    {
        app()->instance(RegisteredPageFields::class, new RegisteredPageFields(app(SchemaRegistry::class), enabled: false));
        self::assertSame(['legacy'], PageFields::names());
        self::assertNull(PageFields::named(self::STEM));
    }

    public function test_latest_nested_schema_is_not_replaced_with_an_older_flat_version(): void
    {
        app(SchemaRegistry::class)->register(['$id' => self::STEM.'/3', 'type' => 'object', 'properties' => [
            'details' => ['type' => 'object'],
        ]]);
        self::assertNull(PageFields::named(self::STEM));
        self::assertNotContains(self::STEM, PageFields::names());
    }

    public function test_child_fields_input_rule_accepts_the_same_registered_stem(): void
    {
        $rule = \Splicewire\Beam\Ux\Data\EntryPageSettingsData::rules()['childFields'][2];
        $errors = [];
        $fail = static function (string $error) use (&$errors): void {
            $errors[] = $error;
        };
        $rule('childFields', self::STEM, $fail);
        self::assertSame([], $errors);
        $rule('childFields', self::STEM.'/2', $fail);
        self::assertCount(1, $errors);
    }

    public function test_optional_fields_may_be_empty_without_becoming_a_json_array(): void
    {
        app(SchemaRegistry::class)->register(['$id' => self::STEM.'/3', 'type' => 'object', 'properties' => [
            'note' => ['type' => 'string'],
        ]]);
        self::assertSame([], PageFields::check(self::STEM, []));
    }
}
