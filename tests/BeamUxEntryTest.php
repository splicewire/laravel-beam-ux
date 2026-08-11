<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * beamux-entry-charter S0 — the BeamUxEntry model resolves and rides beam-core's shared ParticleWriter.
 *
 * The entry has-a a generic BeamParticle body (ADR-0167): the particle carries the migrate-on-read
 * content written through the shared {@see ParticleWriter} (NO fork, emits ONE BeamParticlePersisted);
 * this row carries the flat authoring envelope. Mirrors beam-core's own ParticleWriterTest, scoped to
 * the beam-ux consumer.
 */
class BeamUxEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        // The default WriteGate delegates to the Laravel gate; grant create so the body write passes.
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    public function test_the_entry_has_a_particle_body_written_through_the_shared_pipeline(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $body = ['heading' => 'Hi', 'blocks' => []];
        $particle = $this->app->make(ParticleWriter::class)->write(new BeamParticle, $body);

        $entry = BeamUxEntry::create([
            'slug' => 'hero',
            'type' => 'component',
            'namespace' => 'kit.hero',
            'particle_id' => $particle->id,
        ]);

        // Envelope persisted; residency defaulted to context-following without being set.
        $this->assertDatabaseHas('beam_ux_entries', [
            'id' => $entry->id,
            'slug' => 'hero',
            'residency_mode' => BeamUxEntry::RESIDENCY_CONTEXT_FOLLOWING,
        ]);

        // One post-persist signal from the shared pipeline.
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);

        // The has-a body re-loads through the particle reader.
        $reloaded = BeamUxEntry::with('particle')->findOrFail($entry->id);
        $this->assertNotNull($reloaded->particle);
        $this->assertSame($body, $reloaded->particle->payload);
    }

    /**
     * S1 (ADR-0164) — an mdx page AND a tsx component both round-trip through their format-dispatched
     * codecs. Proves the particle/version/storage treatment is format-generic: the two body languages
     * ride the SAME has-a-particle body, differing only in codec.
     */
    public function test_mdx_page_and_tsx_component_round_trip_through_their_codecs(): void
    {
        $registry = $this->app->make(CodecRegistry::class);

        // A tsx component, body_style: full — the codec injects the auto-import preamble deterministically.
        $tsxSource = 'export const Hero = () => <h1>Hi</h1>;';
        $tsxEntry = BeamUxEntry::create([
            'slug' => 'hero',
            'type' => UxType::Component,
            'format' => UxFormat::Tsx,
            'body_style' => BodyStyle::Full,
            'namespace' => 'kit.hero',
        ]);

        $tsxCodec = $tsxEntry->codec();
        $tsxBody = $tsxCodec->encode($tsxSource, $tsxEntry->body_style);
        $this->assertSame(TsxBodyCodec::AUTO_IMPORT_PREAMBLE, $tsxBody[TsxBodyCodec::PREAMBLE_KEY]);
        $this->assertSame($tsxSource, $tsxCodec->decode($tsxBody));

        // An mdx page — the codec folds in beam-mdx's MdxBody; body_style is meaningless and ignored.
        $mdxSource = "---\ntitle: About\n---\n# About\n\nHello from mdx.";
        $mdxEntry = BeamUxEntry::create([
            'slug' => 'about',
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
            'namespace' => 'site.pages',
        ]);

        $mdxCodec = $mdxEntry->codec();
        $mdxBody = $mdxCodec->encode($mdxSource);
        $this->assertSame('About', $mdxBody['frontmatter']['title']);
        $this->assertSame($mdxSource, $mdxCodec->decode($mdxBody));

        // Dispatch is on format — each entry resolves its own codec.
        $this->assertInstanceOf(TsxBodyCodec::class, $registry->for(UxFormat::Tsx));
        $this->assertSame(UxFormat::Mdx, $mdxCodec->format());
        $this->assertSame('mdx', $mdxCodec->extension());
    }

    /**
     * The `type` axis is ENFORCED to exactly the four structural values — an out-of-taxonomy value is
     * rejected at the enum-cast boundary (never lands in the row). `format` is orthogonal.
     */
    public function test_the_four_types_are_enforced_and_format_is_orthogonal(): void
    {
        // All four valid types persist across formats — {type, format} composes as a matrix.
        foreach (UxType::cases() as $i => $type) {
            $entry = BeamUxEntry::create([
                'slug' => 'e'.$i,
                'type' => $type,
                'format' => $i % 2 === 0 ? UxFormat::Tsx : UxFormat::Mdx,
                'namespace' => 'ns'.$i,
            ]);
            $this->assertInstanceOf(UxType::class, $entry->fresh()->type);
        }

        // An invalid type is rejected — the enum cast throws rather than persisting a fifth kind.
        $this->expectException(\ValueError::class);
        BeamUxEntry::create([
            'slug' => 'bad',
            'type' => 'widget',
            'namespace' => 'ns.bad',
        ]);
    }

    private function createTables(): void
    {
        // The beam-ux table (what the shared migrations ship — S0 create + S1 aspect alter) — built
        // inline so the package test does not depend on the tenancy/central registration seam
        // (exercised end-to-end in the app suite).
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->unique(['namespace', 'slug']);
        });
        // beam-core body tables (mirrors beam-core's ParticleWriterTest fixtures).
        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->uuid('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
