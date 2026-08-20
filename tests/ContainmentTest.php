<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-entry-charter S3 (ADR-0165) — the containment aspect: a realm-rooted containment tree derives
 * the PUBLIC URL, decoupled from `namespace` (the "two trees"). Asserts:
 *  - realm root auto-provisioning (one per realm, idempotent — ticket 03);
 *  - the `realms` fallback stack defaults to `[realm]` and an entry resolves in every listed realm;
 *  - URL inheritance — `./segment` resolves against the PARENT, `/segment` against the realm ROOT;
 *  - the route is decoupled from `namespace` (disk grouping does not touch the URL);
 *  - the composed-down `NavTree` projection of the containment tree.
 */
class ContainmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_the_realm_root_is_auto_provisioned_once_per_realm_and_realms_defaults_to_the_realm(): void
    {
        $this->assertSame(0, BeamUxEntry::query()->count());

        $root = BeamUxEntry::rootFor();
        $this->assertSame('site', $root->realm);
        $this->assertSame(['site'], $root->realms);
        $this->assertSame('realms', $root->namespace);

        // Idempotent — asking again reuses the same root, no duplicate.
        $this->assertTrue(BeamUxEntry::rootFor()->is($root));
        $this->assertSame(1, BeamUxEntry::query()->count());

        // A plain entry with no explicit `realms` defaults its fallback stack to `[realm]`.
        $home = BeamUxEntry::create(['slug' => 'home', 'type' => UxType::Page, 'segment' => '/']);
        $this->assertSame(['site'], $home->realms);
    }

    public function test_an_entry_can_be_created_with_a_multi_realm_fallback_stack_and_resolves_in_each_listed_realm(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'shared-hero',
            'type' => UxType::Page,
            'segment' => '/hero',
            'realm' => 'operator',
            'realms' => ['operator', 'tenant', 'site'],
        ]);

        foreach (['operator', 'tenant', 'site'] as $realm) {
            $tree = $this->app->make(NavProjector::class)->project($realm);
            $this->assertCount(1, $tree->items, "entry must resolve under realm [{$realm}]");
            $this->assertSame('/hero', $tree->items[0]->href);
        }

        // A realm NOT in the stack does not see it.
        $this->assertCount(0, $this->app->make(NavProjector::class)->project('checkout')->items);
        unset($entry);
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

        // `/segment` resets to the realm ROOT, discarding the /blog/testing ancestor path.
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
        // `blog` carries an EXPLICIT title (used verbatim); `discover-songs` has none (humanized-slug fallback).
        $blog = BeamUxEntry::create(['slug' => 'blog', 'title' => 'The Blog', 'type' => UxType::Page, 'segment' => '/blog']);
        BeamUxEntry::create(['slug' => 'testing', 'type' => UxType::Page, 'segment' => 'testing', 'parent_id' => $blog->id]);
        BeamUxEntry::create(['slug' => 'discover-songs', 'type' => UxType::Page, 'segment' => '/discover']);

        $tree = $this->app->make(NavProjector::class)->project('site');

        $this->assertInstanceOf(NavTree::class, $tree);

        // Two top-level nodes; the explicit-title node keeps its author-provided label.
        $this->assertCount(2, $tree->items);

        $blogNode = collect($tree->items)->firstWhere('title', 'The Blog');
        $this->assertNotNull($blogNode, 'an explicit `title` is used verbatim');
        $this->assertSame('/blog', $blogNode->href);
        $this->assertCount(1, $blogNode->children);

        // The child has no explicit title → humanized slug (`testing` → `Testing`); href is the INHERITED URL.
        $this->assertSame('Testing', $blogNode->children[0]->title);
        $this->assertSame('/blog/testing', $blogNode->children[0]->href);

        // A title-less top node falls back to Str::headline(slug): `discover-songs` → `Discover Songs`.
        $discover = collect($tree->items)->firstWhere('href', '/discover');
        $this->assertSame('Discover Songs', $discover->title);
    }

    public function test_non_page_entries_are_excluded_from_the_nav_projection(): void
    {
        // A template shares the default realm but has NO route — it must never surface as a nav
        // destination, even though its null segment would resolve to `/`.
        BeamUxEntry::create(['slug' => 'home', 'type' => UxType::Page, 'segment' => '/']);
        BeamUxEntry::create(['slug' => 'section-template', 'type' => UxType::Template, 'segment' => null]);

        $tree = $this->app->make(NavProjector::class)->project('site');

        // Only the `page` survives; the template is filtered out (no phantom `/` collision).
        $this->assertCount(1, $tree->items);
        $this->assertSame('Home', $tree->items[0]->title);
        $this->assertSame('/', $tree->items[0]->href);
    }

    public function test_unplaced_page_entries_without_a_segment_are_excluded_from_nav(): void
    {
        // An entry can exist without being PLACED in nav — e.g. one auto-provisioned so a page is editable
        // carries a null segment. It must not surface (and several such entries must not all collide at `/`).
        BeamUxEntry::create(['slug' => 'home', 'title' => 'Home', 'type' => UxType::Page, 'segment' => '/']);
        BeamUxEntry::create(['slug' => 'about', 'type' => UxType::Page, 'segment' => null]);
        BeamUxEntry::create(['slug' => 'faq', 'type' => UxType::Page, 'segment' => null]);

        $tree = $this->app->make(NavProjector::class)->project('site');

        $this->assertCount(1, $tree->items);
        $this->assertSame('Home', $tree->items[0]->title);
    }

    public function test_nav_projection_orders_siblings_by_nav_order(): void
    {
        // Insert out of nav order; `nav_order` (not insertion/slug order) must drive the menu sequence.
        BeamUxEntry::create(['slug' => 'songs', 'title' => 'Songs', 'type' => UxType::Page, 'segment' => '/songs', 'nav_order' => 30]);
        BeamUxEntry::create(['slug' => 'home', 'title' => 'Home', 'type' => UxType::Page, 'segment' => '/', 'nav_order' => 10]);
        BeamUxEntry::create(['slug' => 'discover', 'title' => 'Discover', 'type' => UxType::Page, 'segment' => '/discover', 'nav_order' => 20]);

        $tree = $this->app->make(NavProjector::class)->project('site');

        $this->assertSame(['Home', 'Discover', 'Songs'], array_map(fn ($n) => $n->title, $tree->items));
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
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
            $table->integer('nav_order')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
