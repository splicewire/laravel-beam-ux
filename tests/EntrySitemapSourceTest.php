<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Sitemap\SitemapSourceRegistry;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-entry-charter S5 (ADR-0166) — EntrySitemapSource yields URLs for entries where
 * route × published-marking × entitlement ALL hold, and registers onto the free-tier arm's registry.
 *
 * These tests exercise the gate SEAM by re-binding the two ports to fake predicates, proving the source
 * filters end-to-end. The production marking read is `WorkflowMarkingPublishGate` (charter S6); this test
 * proves the port, `EntryWorkflowTest` proves the real gate.
 */
class EntrySitemapSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        config()->set('app.url', 'https://example.test');
    }

    public function test_it_registers_onto_the_arm_registry(): void
    {
        $registry = $this->app->make(SitemapSourceRegistry::class);
        $classes = array_map(fn ($s) => $s::class, $registry->all());

        $this->assertContains(EntrySitemapSource::class, $classes);
    }

    public function test_it_yields_absolute_urls_for_routable_published_public_pages(): void
    {
        BeamUxEntry::create(['slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog']);
        BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        // A component is not routable → skipped by the route gate.
        BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'segment' => '/hero']);

        $urls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));

        $this->assertContains('https://example.test/blog', $urls);
        $this->assertContains('https://example.test/about', $urls);
        $this->assertNotContains('https://example.test/hero', $urls);
    }

    public function test_the_published_marking_gate_filters_unpublished_entries(): void
    {
        $published = BeamUxEntry::create(['slug' => 'live', 'type' => UxType::Page, 'segment' => '/live']);
        $draft = BeamUxEntry::create(['slug' => 'draft', 'type' => UxType::Page, 'segment' => '/draft']);

        // Re-bind the (stub) marking port to a real predicate: only `live` is published.
        $this->app->bind(EntryPublishGate::class, fn () => new class($published->id) implements EntryPublishGate
        {
            public function __construct(private string $publishedId) {}

            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->id === $this->publishedId;
            }
        });

        $urls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));

        $this->assertContains('https://example.test/live', $urls);
        $this->assertNotContains('https://example.test/draft', $urls, 'unpublished entry must not appear');
        unset($draft);
    }

    public function test_the_entitlement_gate_never_leaks_gated_content(): void
    {
        $public = BeamUxEntry::create(['slug' => 'free', 'type' => UxType::Page, 'segment' => '/free']);
        BeamUxEntry::create(['slug' => 'members', 'type' => UxType::Page, 'segment' => '/members']);

        // Re-bind the entitlement port: everything but `free` is gated.
        $this->app->bind(EntryEntitlementGate::class, fn () => new class($public->id) implements EntryEntitlementGate
        {
            public function __construct(private string $publicId) {}

            public function isPublic(BeamUxEntry $entry): bool
            {
                return $entry->id === $this->publicId;
            }
        });

        $urls = $this->urlsFrom($this->app->make(EntrySitemapSource::class));

        $this->assertContains('https://example.test/free', $urls);
        $this->assertNotContains('https://example.test/members', $urls, 'gated content must never leak into the sitemap');
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
            $table->boolean('composable')->default(true);
            $table->string('realm')->default('site')->index();
            $table->uuid('sitemap_id')->nullable()->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create('sitemaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('realm')->default('site')->index();
            $table->string('name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }
}
