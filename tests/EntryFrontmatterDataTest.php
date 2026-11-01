<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\Attributes\Test;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Splicewire\Beam\Mdx\Frontmatter\Contracts\Frontmatter;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterParser;
use Splicewire\Beam\Mdx\Frontmatter\FrontmatterResolver;
use Splicewire\Beam\Ux\Data\EntryFrontmatterData;

/**
 * beam-ux's own declared frontmatter shape (frontmatter-declaration-seam ticket 05) — the first real
 * consumer of the seam, and the demonstration that the shape is per-consumer while the grammar is shared.
 */
class EntryFrontmatterDataTest extends TestCase
{
    #[Test]
    public function it_is_a_declared_shape_with_its_own_versioned_identity(): void
    {
        $this->assertInstanceOf(Frontmatter::class, new EntryFrontmatterData);
        $this->assertInstanceOf(SchemaIdentity::class, new EntryFrontmatterData);
        $this->assertSame('beam-ux/entry-frontmatter', EntryFrontmatterData::schemaName());

        // Distinct from the mdx-shipped default's identity — two shapes, two schemas, one grammar.
        $this->assertNotSame(
            \Splicewire\Beam\Mdx\Frontmatter\FrontmatterData::schemaName(),
            EntryFrontmatterData::schemaName()
        );
    }

    #[Test]
    public function it_claims_beam_ux_vocabulary_that_the_mdx_default_does_not(): void
    {
        $this->assertContains('nav_order', EntryFrontmatterData::fieldNames());
        $this->assertContains('realm', EntryFrontmatterData::fieldNames());

        // The point of per-consumer shapes: beam-ux's containment vocabulary must NOT leak into the
        // format-owning package.
        $this->assertNotContains('nav_order', \Splicewire\Beam\Mdx\Frontmatter\FrontmatterData::fieldNames());
        $this->assertNotContains('realm', \Splicewire\Beam\Mdx\Frontmatter\FrontmatterData::fieldNames());
    }

    #[Test]
    public function it_folds_a_camel_authored_block_into_its_typed_fields(): void
    {
        $parsed = (new FrontmatterParser)->parse(
            "---\nrealm: account\nsegment: /a\nnavOrder: 7\nnavGroup: Build\ntitle: T\n---\nbody\n"
        );

        $data = EntryFrontmatterData::fromFrontmatter($parsed);

        $this->assertSame(7, $data->navOrder);
        $this->assertSame('Build', $data->navGroup);
        $this->assertSame('account', $data->realm);
    }

    #[Test]
    public function it_maps_camel_properties_to_the_stable_snake_case_frontmatter_keys(): void
    {
        $data = EntryFrontmatterData::from([
            'nav_order' => 7,
            'nav_group' => 'Guides',
            'workflow_marking' => 'draft',
        ]);

        $this->assertSame(7, $data->navOrder);
        $this->assertSame('Guides', $data->navGroup);
        $this->assertSame('draft', $data->workflowMarking);
        $this->assertSame([
            'realm' => null,
            'segment' => null,
            'nav_order' => 7,
            'nav_group' => 'Guides',
            'title' => null,
            'layout' => null,
            'template' => null,
            'workflow_marking' => 'draft',
            'leftovers' => [],
        ], $data->toArray());
        $this->assertFalse(property_exists($data, 'nav_order'));
        $this->assertFalse(property_exists($data, 'nav_group'));
        $this->assertFalse(property_exists($data, 'workflow_marking'));
    }

    #[Test]
    public function the_pin_holds_under_a_disagreeing_host_global(): void
    {
        // The only run that can prove a class-level pin: one where the ambient strategy says otherwise.
        config()->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        $data = EntryFrontmatterData::from(['nav_order' => 9, 'realm' => 'site']);

        $this->assertSame(9, $data->navOrder);
        $this->assertSame('site', $data->realm);
    }

    #[Test]
    public function each_multi_word_frontmatter_property_maps_to_its_wire_name(): void
    {
        $reflection = new \ReflectionClass(EntryFrontmatterData::class);

        foreach ([
            'navOrder' => 'nav_order',
            'navGroup' => 'nav_group',
            'workflowMarking' => 'workflow_marking',
        ] as $property => $wireName) {
            $attributes = $reflection->getProperty($property)->getAttributes(MapName::class);
            $this->assertCount(1, $attributes);
            $this->assertSame([$wireName], $attributes[0]->getArguments());
        }
    }

    #[Test]
    public function keys_it_does_not_declare_are_retained(): void
    {
        // schemaType is read by the JS plane at build time; dropping it here would make this shape
        // unable to round-trip a file it did not fully understand.
        $parsed = (new FrontmatterParser)->parse("---\ntitle: T\nschemaType: Article\n---\nbody\n");

        $this->assertSame(['schema_type' => 'Article'], EntryFrontmatterData::fromFrontmatter($parsed)->unknown());
    }

    #[Test]
    public function it_resolves_through_the_shared_resolver_when_declared_as_the_shape(): void
    {
        config()->set('beam.mdx.frontmatter.shape', EntryFrontmatterData::class);

        $parsed = (new FrontmatterParser)->parse("---\nnavOrder: 3\n---\nbody\n");
        $shape = app(FrontmatterResolver::class)->hydrate($parsed);

        $this->assertInstanceOf(EntryFrontmatterData::class, $shape);
        $this->assertSame(3, $shape->navOrder);
    }
}
