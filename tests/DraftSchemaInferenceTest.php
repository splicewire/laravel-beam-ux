<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Inference\TsxPropInference;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-build S9 (`beamux-build/issues/06`) — deterministic prop→draft-schema inference.
 *
 * A freshly-registered `component` is editable immediately because BeamUx infers a JSON-Schema from its
 * `.tsx` props and stores it as `schema_ref`, marked DRAFT (never final). The mapping (name→key, TS
 * type→JSON type, literal-union→enum, `?`→optional, destructure-default→`default`, JSDoc→title/desc) is
 * DETERMINISTIC — the same source yields the same schema byte-for-byte. `page`/`layout`/`template` are
 * excluded (their schema is the composition model's, not props). Inference never fabricates
 * widgets/validation/refs.
 */
class DraftSchemaInferenceTest extends TestCase
{
    private const HERO_TSX = <<<'TSX'
    interface HeroProps {
      /**
       * The banner headline.
       * Shown large at the top of the hero.
       */
      title: string;
      subtitle?: string;
      count: number;
      /** @title Alignment
       * How the text is aligned within the hero.
       */
      align: 'left' | 'center' | 'right';
      featured: boolean;
      tags: string[];
    }

    export const Hero = ({ title, count = 3, align = 'center', featured, subtitle, tags }: HeroProps) => (
      <section>{title}</section>
    );
    TSX;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_the_same_source_yields_the_same_draft_schema_byte_for_byte(): void
    {
        $inference = $this->app->make(TsxPropInference::class);

        $first = $inference->infer(self::HERO_TSX);
        $second = $inference->infer(self::HERO_TSX);

        // Structure-stable AND byte-stable across runs (the determinism commitment).
        $this->assertSame($first, $second);
        $this->assertSame(json_encode($first), json_encode($second));
    }

    public function test_the_mapping_cases_are_covered(): void
    {
        $schema = $this->app->make(TsxPropInference::class)->infer(self::HERO_TSX);

        $props = $schema['properties'];

        // 2020-12 dialect — same shape a graduated authored schema is.
        $this->assertSame(TsxPropInference::DIALECT, $schema['$schema']);
        $this->assertSame('object', $schema['type']);

        // name→key + declaration order preserved.
        $this->assertSame(
            ['title', 'subtitle', 'count', 'align', 'featured', 'tags'],
            array_keys($props),
        );

        // TS type → JSON type.
        $this->assertSame('string', $props['title']['type']);
        $this->assertSame('number', $props['count']['type']);
        $this->assertSame('boolean', $props['featured']['type']);
        $this->assertSame('array', $props['tags']['type']);

        // literal-union → enum (string-typed).
        $this->assertSame('string', $props['align']['type']);
        $this->assertSame(['left', 'center', 'right'], $props['align']['enum']);

        // destructure default → `default`.
        $this->assertSame(3, $props['count']['default']);
        $this->assertSame('center', $props['align']['default']);

        // JSDoc → title + description (first-sentence title fallback).
        $this->assertSame('The banner headline.', $props['title']['title']);
        $this->assertStringContainsString('Shown large', $props['title']['description']);

        // JSDoc explicit @title wins.
        $this->assertSame('Alignment', $props['align']['title']);

        // `?` → optional (dropped from required); a default also drops it; the rest are required.
        $this->assertContains('title', $schema['required']);
        $this->assertContains('featured', $schema['required']);
        $this->assertContains('tags', $schema['required']);
        $this->assertNotContains('subtitle', $schema['required']); // `?`
        $this->assertNotContains('count', $schema['required']);    // default
        $this->assertNotContains('align', $schema['required']);    // default

        // Inference NEVER fabricates widgets/validation/refs — a props map only.
        $encoded = json_encode($schema);
        $this->assertStringNotContainsString('widget', $encoded);
        $this->assertStringNotContainsString('$ref', $encoded);
        $this->assertStringNotContainsString('format', $encoded);
    }

    public function test_infer_stores_the_schema_as_a_draft_on_the_entry(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'hero',
            'type' => UxType::Component,
            'namespace' => 'kit.hero',
        ]);

        $updated = $this->app->make(InferDraftSchema::class)->forEntry($entry, self::HERO_TSX, persist: true);

        // The draft flag is SET (the load-bearing assertion) and schema_ref carries the inferred schema.
        $this->assertTrue($updated->fresh()->schema_is_draft);
        $this->assertNotNull($updated->fresh()->schema_ref);

        $stored = json_decode($updated->fresh()->schema_ref, true);
        $this->assertSame('object', $stored['type']);
        $this->assertArrayHasKey('title', $stored['properties']);
    }

    public function test_page_layout_template_are_excluded_from_prop_inference(): void
    {
        $inference = $this->app->make(TsxPropInference::class);

        foreach ([UxType::Page, UxType::Layout, UxType::Template] as $type) {
            $this->assertFalse($inference->infersFor($type), "{$type->value} must not be inferred");
        }
        $this->assertTrue($inference->infersFor(UxType::Component));

        // inferForType asserts the exclusion — throws rather than emitting a props-shaped schema.
        $this->expectException(\InvalidArgumentException::class);
        $inference->inferForType(UxType::Page, self::HERO_TSX);
    }

    public function test_a_page_entry_is_left_untouched_by_the_draft_action(): void
    {
        $page = BeamUxEntry::create([
            'slug' => 'about',
            'type' => UxType::Page,
            'namespace' => 'site.pages',
        ]);

        $result = $this->app->make(InferDraftSchema::class)->forEntry($page, self::HERO_TSX, persist: true);

        // No prop inference for a page — schema stays unset, draft flag stays false (never fabricated).
        $this->assertNull($result->fresh()->schema_ref);
        $this->assertFalse($result->fresh()->schema_is_draft);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
