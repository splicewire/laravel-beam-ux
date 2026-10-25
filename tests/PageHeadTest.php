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
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Head\PageGraph;
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

        $head = $this->get('/about')->json('props.head');

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
        ], array_slice($head, 0, 10));
        $this->assertStringStartsWith('<script data-inertia="structured-data" type="application/ld+json">', $head[10], 'the @graph closes the head');
        $this->assertCount(11, $head);
    }

    public function test_a_page_carries_one_graph_with_its_node_and_its_breadcrumbs(): void
    {
        // d9/d10: the page's node typed by its Page Type, from its title, SEO Fields, canonical URL and publish dates; a
        // BreadcrumbList from the chain; an Article's author and publisher are the site's publisher.
        $docs = $this->page('docs', ['title' => 'Docs']);
        $this->page('api', [
            'parent_id' => $docs->getKey(), 'title' => 'API Reference', 'seo_description' => 'Every endpoint.',
            'seo_social_image' => '/media/api.png', 'seo_page_type' => 'Article', 'seo_published_date' => '2026-10-01',
            'first_published_at' => '2026-10-02 09:00:00', 'last_published_at' => '2026-10-20 12:30:00',
        ]);

        $graph = $this->graph($this->get('/docs/api')->json('props.head'));

        $this->assertSame('https://schema.org', $graph['@context']);
        $this->assertEquals([
            '@type' => 'Article',
            '@id' => 'https://site.test/docs/api#webpage',
            'url' => 'https://site.test/docs/api',
            'name' => 'API Reference',
            'description' => 'Every endpoint.',
            'image' => 'https://site.test/media/api.png',
            'datePublished' => '2026-10-01',
            'dateModified' => '2026-10-20T12:30:00+00:00',
            'isPartOf' => ['@id' => 'https://site.test/#website'],
            'breadcrumb' => ['@id' => 'https://site.test/docs/api#breadcrumb'],
            'author' => ['@id' => 'https://site.test/#organization'],
            'publisher' => ['@id' => 'https://site.test/#organization'],
        ], $this->node($graph, 'Article'));
        $this->assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Acme', 'item' => 'https://site.test/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Docs', 'item' => 'https://site.test/docs'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'API Reference', 'item' => 'https://site.test/docs/api'],
        ], $this->node($graph, 'BreadcrumbList')['itemListElement']);
        $this->assertSame('https://site.test/docs/api#breadcrumb', $this->node($graph, 'BreadcrumbList')['@id']);
        $this->assertSame('Acme', $this->node($graph, 'Organization')['name'], 'a node the page names is in the graph');
    }

    public function test_without_the_schema_org_package_a_page_keeps_its_head_and_carries_no_graph(): void
    {
        // GO 32 (2026-10-25): five live hosts that symlink beam-ux don't install laravel-data-schema-org. The graph is
        // the package's to project, so without it a page carries no @graph, and its title, description, canonical and
        // social tags are unchanged.
        $this->app->instance(PageGraph::class, new PageGraph(app(UrlResolver::class), installed: false));
        $this->page('about', ['title' => 'About us', 'seo_description' => 'Who we are.']);

        $head = $this->get('/about')->assertOk()->json('props.head');

        $this->assertSame('<title data-inertia="title">About us - Acme</title>', $head[0]);
        $this->assertContains('<meta data-inertia="description" name="description" content="Who we are.">', $head);
        $this->assertContains('<link data-inertia="canonical" rel="canonical" href="https://site.test/about">', $head);
        $this->assertSame([], preg_grep('/application\/ld\+json/', $head), 'no JSON-LD without the package');
    }

    public function test_beam_ux_suggests_the_schema_org_package_rather_than_requiring_it(): void
    {
        // A host that wants Structured Data requires laravel-data-schema-org itself, as the starters do.
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('schemastud/laravel-data-schema-org', $manifest['require']);
        $this->assertArrayHasKey('schemastud/laravel-data-schema-org', $manifest['require-dev']);
        $this->assertArrayHasKey('schemastud/laravel-data-schema-org', $manifest['suggest']);
    }

    public function test_every_page_below_the_root_starts_its_trail_at_the_sites_home(): void
    {
        // EP-28 receipt 2026-10-25: the starter's /about is a parentless page with a root-absolute segment, so the chain
        // the renderer walks holds only the page, and it had no trail. The site's home heads every trail, and a
        // root-absolute segment restarts the trail there, as it restarts the URL.
        $this->page('solo', ['parent_id' => null, 'segment' => '/solo', 'title' => 'Solo']);
        $shell = $this->page('internal', ['title' => 'Internal']);
        $docs = $this->page('docs', ['parent_id' => $shell->getKey(), 'segment' => '/beam/docs', 'title' => 'Docs']);
        $this->page('api', ['parent_id' => $docs->getKey(), 'title' => 'API']);
        $trail = fn (string $path) => array_map(
            fn (array $item) => [$item['name'], $item['item']],
            $this->node($this->graph($this->get($path)->json('props.head')), 'BreadcrumbList')['itemListElement'],
        );

        $this->assertSame([['Acme', 'https://site.test/'], ['Solo', 'https://site.test/solo']], $trail('/solo'));
        $this->assertSame([['Acme', 'https://site.test/'], ['Docs', 'https://site.test/beam/docs']], $trail('/beam/docs'));
        $this->assertSame([
            ['Acme', 'https://site.test/'], ['Docs', 'https://site.test/beam/docs'], ['API', 'https://site.test/beam/docs/api'],
        ], $trail('/beam/docs/api'));
    }

    public function test_an_unpublished_date_falls_back_to_the_first_publish_and_a_plain_page_is_a_web_page(): void
    {
        // d6: an empty published date is the date of the page's first Publish.
        $this->page('about', ['title' => 'About', 'first_published_at' => '2026-10-02 09:00:00']);

        $node = $this->node($this->graph($this->get('/about')->json('props.head')), 'WebPage');

        $this->assertSame('2026-10-02T09:00:00+00:00', $node['datePublished']);
        $this->assertArrayNotHasKey('author', $node);
        $this->assertArrayNotHasKey('description', $node);
    }

    public function test_the_home_page_carries_the_website_and_its_publisher(): void
    {
        // d9: the WebSite and its publisher, an Organization from the brand or a Person where the host declares one.
        config(['beam.ux.namespace' => 'starter', 'beam.brand.logo' => '/brand/logo.svg']);
        $this->page('home', ['namespace' => 'starter', 'segment' => null, 'title' => 'Home']);

        $graph = $this->graph(PageHead::forPage('home', Request::create('https://whatever.test/')));

        $this->assertEquals([
            '@type' => 'WebSite', '@id' => 'https://site.test/#website', 'url' => 'https://site.test/', 'name' => 'Acme',
            'publisher' => ['@id' => 'https://site.test/#organization'],
        ], $this->node($graph, 'WebSite'));
        $this->assertEquals([
            '@type' => 'Organization', '@id' => 'https://site.test/#organization', 'name' => 'Acme', 'url' => 'https://site.test/',
            'logo' => 'https://site.test/brand/logo.svg',
        ], $this->node($graph, 'Organization'));
        $this->assertNull($this->node($graph, 'BreadcrumbList'), 'the root has no trail');

        config(['beam.ux.head.publisher' => 'Person']);
        $person = $this->graph(PageHead::forPage('home', Request::create('https://whatever.test/')));
        $this->assertSame('https://site.test/#person', $this->node($person, 'WebSite')['publisher']['@id']);
        $this->assertSame('Acme', $this->node($person, 'Person')['name']);
    }

    public function test_a_profile_page_names_the_publisher_as_its_main_entity(): void
    {
        // d10: a ProfilePage's main entity is the Record on a Record Page, and otherwise the site's publisher.
        $this->page('me', ['title' => 'Me', 'seo_page_type' => 'ProfilePage']);
        $this->page('all', ['title' => 'All', 'seo_page_type' => 'CollectionPage']);

        $profile = $this->graph($this->get('/me')->json('props.head'));
        $this->assertSame(['@id' => 'https://site.test/#organization'], $this->node($profile, 'ProfilePage')['mainEntity']);
        $this->assertNotNull($this->node($profile, 'Organization'));
        $this->assertNotNull($this->node($this->graph($this->get('/all')->json('props.head')), 'CollectionPage'));
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
     * The page's one `@graph`, from the one Structured Data element its head carries.
     *
     * @param  list<string>  $head
     * @return array<string, mixed>
     */
    private function graph(array $head): array
    {
        $scripts = array_values(preg_grep('#^<script data-inertia="structured-data" type="application/ld\+json">#', $head));
        $this->assertCount(1, $scripts, 'one @graph per page');

        return json_decode((string) preg_replace('#^<script[^>]*>|</script>$#', '', $scripts[0]), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $graph */
    private function node(array $graph, string $type): ?array
    {
        foreach ($graph['@graph'] as $node) {
            if ($node['@type'] === $type) {
                return $node;
            }
        }

        return null;
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
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });
    }
}
