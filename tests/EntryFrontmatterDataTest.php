<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\Attributes\Test;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
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

        $this->assertSame(7, $data->nav_order);
        $this->assertSame('Build', $data->nav_group);
        $this->assertSame('account', $data->realm);
    }

    #[Test]
    public function the_pin_holds_under_a_disagreeing_host_global(): void
    {
        // The only run that can prove a class-level pin: one where the ambient strategy says otherwise.
        config()->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        $data = EntryFrontmatterData::from(['nav_order' => 9, 'realm' => 'site']);

        $this->assertSame(9, $data->nav_order);
        $this->assertSame('site', $data->realm);
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
        $this->assertSame(3, $shape->nav_order);
    }
}
