<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Sitemap\SiloVisibilityEntitlementGate;
use Splicewire\Beam\Ux\Tests\Fixtures\Silo as FixtureSilo;
use Splicewire\Beam\Ux\Tests\Fixtures\Tag as FixtureTag;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-entry-charter S7 (ADR-0165 §2) — the classification-facet aspect. `BeamSilo` (hierarchical) +
 * `BeamTag` (flat) attach to a {@see BeamUxEntry} as OPTIONAL polymorphic facets that classify but NEVER
 * determine the canonical URL (containment does, S3). Asserts:
 *  - silos/tags attach via the `siloable`/`taggable` morphs (pivot-based; NO new entries column);
 *  - the entry's route is UNCHANGED with or without facets (facets never influence the URL);
 *  - facets are OPTIONAL — a fragment carries none (null/empty) and that is fine;
 *  - the Silo-visibility entitlement gate is a re-bindable PORT that hides a restricted-silo entry from
 *    the sitemap while the default (public) binding does not.
 */
class TaxonomyFacetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // Host-bind the concrete taxonomy models the facet concern resolves (the parameterization point:
        // a host subclassing BeamSilo/BeamTag rebinds these). Lightweight fixtures keep the harness thin.
        config()->set('beam.taxonomy.models.tag', FixtureTag::class);
        config()->set('beam.taxonomy.models.silo', FixtureSilo::class);

        config()->set('app.url', 'https://example.test');
    }

    public function test_an_entry_carries_silos_and_tags_via_the_morphs(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => UxType::Page, 'segment' => '/guide']);

        $silo = FixtureSilo::create(['name' => 'Docs', 'slug' => 'docs']);
        $tag = FixtureTag::create(['name' => 'beta', 'slug' => 'beta']);

        $entry->silos()->attach($silo->id);
        $entry->attachTag($tag);

        $fresh = BeamUxEntry::find($entry->id);
        $this->assertTrue($fresh->silos->contains($silo->id), 'silo attaches via the siloable morph');
        $this->assertTrue($fresh->tags->contains($tag->id), 'tag attaches via the taggable morph');

        // The morphs are pivot-based — the entry row itself gained no facet column in S7.
        $this->assertFalse(Schema::hasColumn('beam_ux_entries', 'silo_id'));
        $this->assertFalse(Schema::hasColumn('beam_ux_entries', 'tag_id'));
    }

    public function test_facets_never_change_the_route(): void
    {
        $resolver = $this->app->make(UrlResolver::class);

        $blog = BeamUxEntry::create(['slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog']);
        $post = BeamUxEntry::create([
            'slug' => 'hello',
            'type' => UxType::Page,
            'segment' => 'hello',
            'parent_id' => $blog->id,
        ]);

        // URL BEFORE any classification.
        $urlBefore = $resolver->resolve($post);
        $this->assertSame('/blog/hello', $urlBefore);

        // Pile on silos + tags.
        $post->silos()->attach(FixtureSilo::create(['name' => 'News', 'slug' => 'news'])->id);
        $post->attachTag(FixtureTag::create(['name' => 'featured', 'slug' => 'featured']));

        // URL AFTER classification is identical — facets never influence the canonical URL (ADR-0165 §2).
        $post->refresh();
        $this->assertSame($urlBefore, $resolver->resolve($post), 'facets must never change the route');
        $this->assertSame($urlBefore, $post->url());
    }

    public function test_facets_are_optional_a_fragment_carries_none(): void
    {
        // A component fragment with no containment + no classification is valid: facets are optional.
        $fragment = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component]);

        $this->assertCount(0, $fragment->silos, 'silos are optional — empty for a fragment');
        $this->assertCount(0, $fragment->tags, 'tags are optional — empty for a fragment');
    }

    public function test_the_silo_visibility_gate_hides_a_restricted_silo_entry_from_the_sitemap(): void
    {
        $free = BeamUxEntry::create(['slug' => 'free', 'type' => UxType::Page, 'segment' => '/free']);
        $members = BeamUxEntry::create(['slug' => 'members', 'type' => UxType::Page, 'segment' => '/members']);

        // A public silo does not gate; a restricted (members-only) silo does.
        $public = FixtureSilo::create(['name' => 'Open', 'slug' => 'open', 'visibility' => 'public']);
        $restricted = FixtureSilo::create(['name' => 'Members', 'slug' => 'members', 'visibility' => 'members']);

        $free->silos()->attach($public->id);
        $members->silos()->attach($restricted->id);

        // DEFAULT binding (PublicEntitlementGate) — both appear.
        $defaultUrls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));
        $this->assertContains('https://example.test/free', $defaultUrls);
        $this->assertContains('https://example.test/members', $defaultUrls, 'default gate is public — no facet gating');

        // Re-bind the PORT to the Silo-visibility gate — the restricted-silo entry is now hidden.
        $this->app->bind(EntryEntitlementGate::class, SiloVisibilityEntitlementGate::class);

        $gatedUrls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));
        $this->assertContains('https://example.test/free', $gatedUrls, 'a public-silo entry stays visible');
        $this->assertNotContains('https://example.test/members', $gatedUrls, 'a restricted-silo entry is hidden from the sitemap');
    }

    /**
     * @return list<string>
     */
    private function urlsFrom(EntrySitemapSource $source): array
    {
        return array_map(fn (Url $u) => $u->url, iterator_to_array($source->urls(), false));
    }

    private function createTables(): void
    {
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
        // beam-taxonomy's tables (create migrations ship in tower for the host; the beam-ux harness stands
        // up the minimal shape the morphs need — mirrors tower's tenant migrations).
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 512);
            $table->string('slug', 512);
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->uuid('taggable_id');
            $table->string('taggable_type');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });

        Schema::create('silos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug');
            $table->string('visibility')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('siloables', function (Blueprint $table) {
            $table->foreignUuid('silo_id')->constrained()->cascadeOnDelete();
            $table->uuid('siloable_id');
            $table->string('siloable_type');
            $table->unique(['silo_id', 'siloable_id', 'siloable_type']);
        });
    }
}
