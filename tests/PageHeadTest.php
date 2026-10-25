<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Sitemap\Resolvers\SitemapBaseUrlResolver;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Head\PageHead;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0234 d1–d4 (EP-28 PS-09, PS-10): the renderer composes a page's head on the server, as the elements Inertia's
 * server head takes as they are, so the raw HTML carries them before any script runs.
 */
class PageHeadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts', 'beam.brand.name' => 'Acme', 'app.url' => 'https://central.test']);

        // The canonical URL hangs off the sitemap's port, not off APP_URL (d4): a Site Host rebinds the port.
        $this->app->instance(SitemapBaseUrlResolver::class, new class implements SitemapBaseUrlResolver
        {
            public function baseUrl(): string
            {
                return 'https://site.test';
            }
        });

        $this->app->bind(EntryRenderer::class, fn () => new class implements EntryRenderer
        {
            public function render(string $page, array $props): Response
            {
                return new JsonResponse(['page' => $page, 'props' => $props]);
            }
        });

        Route::beamUxSite('site/entry');
    }

    public function test_a_public_page_carries_its_title_description_canonical_and_social_tags(): void
    {
        $this->page('about', [
            'title' => 'About us', 'seo_description' => 'Who we are.', 'seo_social_image' => '/media/about.png', 'seo_page_type' => 'Article',
        ]);

        $this->assertSame([
            '<title data-inertia="title">About us - Acme</title>',
            '<meta data-inertia="description" name="description" content="Who we are.">',
            '<link data-inertia="canonical" rel="canonical" href="https://site.test/about">',
            '<meta data-inertia="og:type" property="og:type" content="article">',
            '<meta data-inertia="og:site_name" property="og:site_name" content="Acme">',
            '<meta data-inertia="og:title" property="og:title" content="About us">',
            '<meta data-inertia="og:description" property="og:description" content="Who we are.">',
            '<meta data-inertia="og:url" property="og:url" content="https://site.test/about">',
            '<meta data-inertia="og:image" property="og:image" content="https://site.test/media/about.png">',
            '<meta data-inertia="twitter:card" name="twitter:card" content="summary_large_image">',
        ], $this->get('/about')->json('props.head'));
    }

    public function test_a_page_with_no_seo_fields_still_names_itself_and_its_canonical_url(): void
    {
        // A page nobody filled SEO Fields for still beats today's brand-name-only title: a link preview reads its own title.
        $this->page('contact', ['title' => 'Contact', 'seo_social_image' => 'https://cdn.example/contact.png']);

        $head = $this->get('/contact')->json('props.head');

        $this->assertContains('<title data-inertia="title">Contact - Acme</title>', $head);
        $this->assertContains('<meta data-inertia="og:type" property="og:type" content="website">', $head);
        $this->assertContains('<meta data-inertia="og:image" property="og:image" content="https://cdn.example/contact.png">', $head, 'an absolute image is kept as authored');
        $this->assertSame([], preg_grep('/name="description"|og:description/', $head), 'no description is invented');
    }

    public function test_the_brand_title_template_applies_once_on_the_server(): void
    {
        // The same rule as beam-inertia's brandTitle: the client passes a server title through, so this is the only place.
        config(['beam.brand.title_template' => ':title | :name']);
        $this->page('about', ['title' => 'About us']);
        $this->page('untitled', ['title' => null]);

        $this->assertSame('<title data-inertia="title">About us | Acme</title>', $this->get('/about')->json('props.head.0'));
        $this->assertSame('<title data-inertia="title">Acme</title>', $this->get('/untitled')->json('props.head.0'), 'an untitled page reads as the brand');
    }

    public function test_what_an_author_typed_is_escaped(): void
    {
        $this->page('quoted', ['title' => 'Tom & "Jerry" <b>', 'seo_description' => '<script>alert(1)</script>']);

        $head = implode("\n", $this->get('/quoted')->json('props.head'));

        $this->assertStringContainsString('<title data-inertia="title">Tom &amp; &quot;Jerry&quot; &lt;b&gt; - Acme</title>', $head);
        $this->assertStringContainsString('content="&lt;script&gt;alert(1)&lt;/script&gt;"', $head);
        $this->assertStringNotContainsString('<script>', $head);
    }

    public function test_a_page_a_guest_cannot_read_gets_its_title_and_noindex_only(): void
    {
        // d3: ADR-0209 §8's restricted chain. Its description, social tags and canonical URL are never composed.
        $this->page('members', [
            'title' => 'Members', 'seo_description' => 'Members only.', 'seo_social_image' => '/media/members.png', 'access' => ['auth'],
        ]);
        $this->actingAs($this->reader());

        $this->assertSame([
            '<title data-inertia="title">Members - Acme</title>',
            '<meta data-inertia="robots" name="robots" content="noindex">',
        ], $this->get('/members')->json('props.head'));
    }

    public function test_the_head_is_a_core_prop_a_layout_contributor_cannot_replace(): void
    {
        $this->page('docs', ['layout' => 'DocsLayout']);
        $this->app->make(\Splicewire\Beam\Ux\Http\EntryPageProps::class)->contribute('DocsLayout', fn () => ['head' => ['<title>hijacked</title>']]);

        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);

        $this->get('/docs');
    }

    public function test_a_host_coded_page_composes_the_same_head_with_the_helper(): void
    {
        // d2: the starter's `/` renders site/home itself, so it passes the head it composes from the page's own row.
        config(['beam.ux.namespace' => 'starter']);
        $this->page('home', ['format' => UxFormat::Tsx, 'namespace' => 'starter', 'segment' => null, 'title' => 'Home', 'seo_description' => 'The front door.']);

        $head = PageHead::forPage('home', Request::create('https://whatever.test/'));

        $this->assertSame('<title data-inertia="title">Home - Acme</title>', $head[0]);
        $this->assertContains('<link data-inertia="canonical" rel="canonical" href="https://site.test/">', $head);
        $this->assertContains('<meta data-inertia="description" name="description" content="The front door.">', $head);
        $this->assertSame([], PageHead::forPage('nowhere', Request::create('/')), 'no row: the root template keeps its fallback title');
    }

    public function test_the_root_template_slot_prints_the_head_or_the_brand_title(): void
    {
        // The packaged slot sits inside <x-inertia::head>, which prints it wherever SSR doesn't run (d1).
        $head = ['<title data-inertia="title">About us - Acme</title>', '<meta data-inertia="description" name="description" content="x">'];

        // deleteCachedView: a compiled copy of this one-line template outlives the run that compiled it.
        $this->assertSame(implode("\n", $head), trim(Blade::render('@beamPageHead', ['page' => ['props' => ['head' => $head]]], true)));
        $this->assertSame('<title>Acme</title>', trim(Blade::render('@beamPageHead', ['page' => ['props' => []]], true)), 'a page with no head keeps the fallback');
    }

    /**
     * Force-filled because Publish writes the SEO Fields onto the row itself; they are not mass-assignable.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function page(string $slug, array $attributes = []): BeamUxEntry
    {
        return tap((new BeamUxEntry)->forceFill(array_merge([
            'slug' => $slug, 'type' => UxType::Page, 'format' => UxFormat::Mdx,
            'segment' => $slug, 'parent_id' => BeamUxEntry::rootFor()->getKey(),
        ], $attributes)))->save();
    }

    private function reader(): User
    {
        return new class extends User
        {
            protected $table = 'users';

            public function getAuthIdentifier(): int
            {
                return 1;
            }
        };
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
            $table->string('segment')->nullable()->index();
            $table->integer('nav_order')->nullable();
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->json('requirements')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('layout')->nullable();
            $table->string('template')->nullable();
            $table->string('workflow_version')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_social_image')->nullable();
            $table->text('seo_page_type')->nullable();
            $table->text('seo_published_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });
    }
}
