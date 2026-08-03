<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\Sitemap;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-entry-charter S3 (ADR-0165) — the containment aspect: a realm/sitemap-rooted containment tree
 * derives the PUBLIC URL, decoupled from `namespace` (the "two trees"). Asserts:
 *  - default sitemap auto-provisioning (one per site, FK-defaulted, idempotent);
 *  - URL inheritance — `./segment` resolves against the PARENT, `/segment` against the realm/sitemap ROOT;
 *  - the route is decoupled from `namespace` (disk grouping does not touch the URL);
 *  - the free-tier `NavTree` projection of the containment tree.
 */
class ContainmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_the_default_sitemap_is_auto_provisioned_once_per_site_and_the_fk_defaults_to_it(): void
    {
        $this->assertSame(0, Sitemap::query()->count());

        // Creating the first entry (no sitemap set) mints AND defaults onto the one-per-site default.
        $home = BeamUxEntry::create(['slug' => 'home', 'type' => UxType::Page, 'segment' => '/']);

        $this->assertSame(1, Sitemap::query()->count());
        $default = Sitemap::forRealm();
        $this->assertTrue($default->is_default);
        $this->assertSame('site', $default->realm);
        $this->assertSame($default->getKey(), $home->sitemap_id, 'the sitemap_id FK defaults to the auto-provisioned default');

        // A SECOND entry reuses the same default — provisioning is idempotent (no second sitemap).
        $about = BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => 'about']);
        $this->assertSame(1, Sitemap::query()->count());
        $this->assertSame($default->getKey(), $about->sitemap_id);
    }

    public function test_relative_segment_resolves_against_the_parent_absolute_against_the_root(): void
    {
        $resolver = $this->app->make(UrlResolver::class);

        // /blog  →  /blog/testing (parent-relative)  →  /reset (root-absolute, ignores the /blog/testing above)
        $blog = BeamUxEntry::create(['slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog']);
        $testing = BeamUxEntry::create(['slug' => 'testing', 'type' => UxType::Page, 'segment' => 'testing', 'parent_id' => $blog->id]);
        $relPost = BeamUxEntry::create(['slug' => 'my-post', 'type' => UxType::Page, 'segment' => './deep', 'parent_id' => $testing->id]);
        $absPost = BeamUxEntry::create(['slug' => 'landing', 'type' => UxType::Page, 'segment' => '/reset', 'parent_id' => $testing->id]);

        // Root-absolute segment on a top node.
        $this->assertSame('/blog', $resolver->resolve($blog));

        // Bare segment is parent-relative (folder semantics): appended under the parent's path.
        $this->assertSame('/blog/testing', $resolver->resolve($testing));

        // `./segment` is equivalent to a bare relative segment — resolves against the parent.
        $this->assertSame('/blog/testing/deep', $resolver->resolve($relPost));

        // `/segment` resets to the realm/sitemap ROOT, discarding the /blog/testing ancestor path.
        $this->assertSame('/reset', $resolver->resolve($absPost));

        // The accessor delegates to the same resolver.
        $this->assertSame('/blog/testing/deep', $relPost->url());
    }

    public function test_the_public_route_is_decoupled_from_namespace(): void
    {
        $resolver = $this->app->make(UrlResolver::class);

        // namespace files this on disk under `articles.2026`; the URL is inherited from the tree, NOT that.
        $blog = BeamUxEntry::create(['slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog', 'namespace' => 'kit.marketing']);
        $post = BeamUxEntry::create([
            'slug' => 'hello',
            'type' => UxType::Page,
            'segment' => 'hello',
            'parent_id' => $blog->id,
            'namespace' => 'articles.2026.august',
        ]);

        // URL follows containment; the wildly-different namespace never leaks into the permalink.
        $this->assertSame('/blog/hello', $resolver->resolve($post));
        $this->assertNotSame($post->namespace, ltrim($resolver->resolve($post), '/'));
    }

    public function test_the_containment_tree_projects_into_a_free_tier_navtree(): void
    {
        $blog = BeamUxEntry::create(['slug' => 'blog', 'type' => UxType::Page, 'segment' => '/blog']);
        BeamUxEntry::create(['slug' => 'testing', 'type' => UxType::Page, 'segment' => 'testing', 'parent_id' => $blog->id]);
        BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => '/about']);

        $tree = $this->app->make(NavProjector::class)->project(Sitemap::forRealm());

        $this->assertInstanceOf(NavTree::class, $tree);

        // Two top-level nodes (blog, about); blog has one child whose href is the INHERITED URL.
        $this->assertCount(2, $tree->items);

        $blogNode = collect($tree->items)->firstWhere('title', 'blog');
        $this->assertSame('/blog', $blogNode->href);
        $this->assertCount(1, $blogNode->children);
        $this->assertSame('/blog/testing', $blogNode->children[0]->href);
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
