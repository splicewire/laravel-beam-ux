<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\Analytics\SiteAreaAbility;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Frame\RouteContextProjector;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * The Site area in TENANT scope (analytics-area 23): a tenant owner/admin who can edit their site sees Published, Indexed
 * and Sitemap-issue counts for THEIR OWN site, read from the tenant-readable Site map — the sitemap feed's real verdict
 * (`search.listed`), not the host's operator diagnostics. No host machinery: no mirror figure, and the figures carry no
 * file paths. Install scope keeps Sitemap health, proven in {@see SiteAreaTest}.
 */
class TenantSiteAreaTest extends SiteAreaTestCase
{
    protected function realm(): string
    {
        return 'tenant';
    }

    /** analytics-area 23: the tenant realm seats the tenant-readable Site map (and Redirects), not the operator diagnostics. */
    protected function destinations(): array
    {
        return [SiteArea::SITE_MAP, SiteArea::REDIRECTS];
    }

    protected function setUp(): void
    {
        parent::setUp(); // runs createEntriesTable() (overridden below) + the redirects table + the entry-edit gates

        config()->set('app.url', 'https://tenant.test');

        // Tenant scope: a current tenant makes laravel-beam-analytics' AnalyticsScope::fromTenancy() yield Tenant.
        $this->app->instance(TenancyContext::class, new class implements TenancyContext
        {
            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function currentTenant(): ?object
            {
                return (object) ['id' => 'acme'];
            }

            public function currentTenantId(): int|string|null
            {
                return 'acme';
            }

            public function centralConnection(): ?string
            {
                return null;
            }
        });

        // The host's tenant site-editing ability (the Site area's gate in tenant scope), held here by the site editors.
        Gate::define(SiteAreaAbility::TENANT_ABILITY, fn (?User $user = null): bool => in_array($user?->id, $this->entitlements->editors, true));
        // The entries model's own read policy admits the Site map, as it admits beam-ux-entry: a site editor may list.
        Gate::policy(BeamUxEntry::class, TenantEntriesPolicy::class);

        // The host toolchain seam livePage() compiles through (as the native component harness sets it).
        Storage::fake('component-artifacts');
        Storage::fake('component-mirror');
        config(['beam.ux.compile.disk' => 'component-artifacts', 'beam.ux.storage.mirror_disk' => 'component-mirror']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(workingDirectory: dirname(__DIR__, 2).'/tests/Fixtures/entry-body-host'));
    }

    /** Publication-capable entries + versioning, so the Site map projects publishedReadable and the sitemap's own verdict. */
    protected function createEntriesTable(): void
    {
        $core = dirname((new \ReflectionClass(BeamServiceProvider::class))->getFileName(), 2);
        foreach (['create_beam_particles_table', 'create_beam_versions_table', 'create_activity_log_table'] as $name) {
            (require $core.'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        foreach ([
            'create_beam_ux_entries_table', 'add_page_settings_to_beam_ux_entries_table', 'add_child_fields_to_beam_ux_entries_table',
            'add_record_page_to_beam_ux_entries_table', 'add_component_kind_to_beam_ux_entries_table', 'add_component_names_to_beam_ux_entries_table',
        ] as $name) {
            (require dirname(__DIR__, 2).'/database/migrations/shared/'.$name.'.php.stub')->up();
        }
        (require $core.'/database/migrations/shared/create_beam_git_repos_table.php.stub')->up();
        (require dirname(__DIR__, 2).'/database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
    }

    private function livePage(string $segment): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div',
            'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]])->headVersion);

        return $page->fresh();
    }

    /** about + pricing: published and listed. beta: published but unlistable — a sitemap issue. draft: unpublished. 3 published, 2 listed, 1 issue. */
    private function seedTenantSite(): void
    {
        $this->livePage('about');
        $this->livePage('pricing');
        $this->livePage('beta')->forceFill(['traverse' => ['root']])->save();
        BeamUxEntry::create(['slug' => 'draft', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'draft']);
        // The publish gate holds 'draft' back, so it is neither published nor in the feed.
        $this->app->bind(EntryPublishGate::class, fn () => new class implements EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->slug !== 'draft';
            }
        });
    }

    public function test_a_tenant_sees_its_own_site_figures_from_the_site_map_with_no_host_diagnostics(): void
    {
        $this->seedTenantSite();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));

        $figures = $this->figures();

        // Published = pages with a published version; Indexed = the sitemap feed's real verdict; Sitemap issues = published
        // yet not in the feed. beta is published but unlistable.
        $this->assertSame([3, 2, 1], [$figures['published']['value'], $figures['indexed']['value'], $figures['sitemap-issues']['value']]);
        $this->assertSame(4, $this->listed(SiteArea::SITE_MAP), 'the figures count over the tenant\'s own Site map rows');

        // No host diagnostics in tenant scope: no mirror figure (it is Install-only), and the figures carry no host machinery.
        $this->assertArrayNotHasKey('mirror', $figures);
    }

    public function test_the_indexed_figure_is_the_sitemap_feeds_verdict_not_the_operator_diagnostics_shortcut(): void
    {
        $this->seedTenantSite();
        $beta = BeamUxEntry::where('slug', 'beta')->firstOrFail();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));

        // Sitemap health's diagnostics shortcut (route × published × entitled) calls beta indexed...
        $this->assertTrue(\Splicewire\Beam\Ux\Data\SitemapHealthRowData::project($beta->fresh())->indexed, 'the operator shortcut would count beta');
        // ...but the tenant Indexed figure is the sitemap feed's own verdict, which leaves beta out (2, not 3).
        $this->assertSame(2, $this->figures()['indexed']['value'], 'the tenant figure reads the Site map, not the operator diagnostics');
    }

    /**
     * analytics-area 29: five Redirects, three of which lead to the tenant's own pages. /old-about reaches about by path,
     * /older-about reaches it through /old-about (a chain, still one Redirect), and /old-pricing names pricing by identity.
     * /elsewhere leaves the site and /gone names a path no page answers, so neither leads to a page.
     */
    private function seedTenantRedirects(): void
    {
        $pricing = BeamUxEntry::where('slug', 'pricing')->firstOrFail();
        BeamUxRedirect::withoutEvents(function () use ($pricing): void {
            BeamUxRedirect::create(['realm' => 'site', 'path' => '/old-about', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/about']);
            BeamUxRedirect::create(['realm' => 'site', 'path' => '/older-about', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/old-about']);
            BeamUxRedirect::create(['realm' => 'site', 'path' => '/old-pricing', 'match' => 'exact', 'target_kind' => 'page', 'target_entry_id' => $pricing->getKey()]);
            BeamUxRedirect::create(['realm' => 'site', 'path' => '/elsewhere', 'match' => 'exact', 'target_kind' => 'address', 'target_url' => 'https://example.com/']);
            BeamUxRedirect::create(['realm' => 'site', 'path' => '/gone', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/nowhere']);
        });
    }

    /**
     * Serve the frame socket in the TENANT realm, as a host's tenant mount does (`TowerFrameRoutes::mount('tenant')` sets
     * `realm` on every socket route). Without it a signed-in reader with no current team resolves to the user realm
     * ({@see \Splicewire\Beam\Ux\Frame\DefaultDashboardRealm}), whose rail holds none of the Site area's pages, so no figure
     * could link anywhere. Returns the tenant realm's own leaves (route name to href).
     *
     * @return array<string, string>
     */
    private function servedInTenantRealm(): array
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'frame/')) {
                $route->defaults('realm', 'tenant');
            }
        }

        return app(RouteContextProjector::class)->hrefs('tenant');
    }

    /** The Site map's own rows, as the viewer lists them: the figure must equal the sum of their inbound counts. */
    private function siteMapInbound(): int
    {
        return array_sum(array_map(fn (array $row): int => (int) ($row['redirects']['count'] ?? 0),
            $this->getJson('frame/resources/'.SiteArea::SITE_MAP.'?per_page=100')->assertOk()->json('data')));
    }

    public function test_the_tenant_redirects_figure_counts_the_redirects_that_lead_to_its_own_pages(): void
    {
        $this->seedTenantSite();
        $this->seedTenantRedirects();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));

        $figure = $this->figures()['redirects'];

        $this->assertSame(3, $figure['value'], 'the three Redirects that reach a page; the off-site and dead-path ones are not counted');
        $this->assertSame('Redirects to your pages', $figure['label'], 'the tenant figure says what it counts');
        $this->assertSame($this->siteMapInbound(), $figure['value'], 'the figure is the Site map rows\' own inbound counts, summed');
        $this->assertSame(5, $this->listed(SiteArea::REDIRECTS), 'the Redirects list itself holds all five');
    }

    public function test_where_the_rail_shows_the_redirects_list_the_figure_links_there(): void
    {
        $this->seedTenantSite();
        $this->seedTenantRedirects();
        $leaves = $this->servedInTenantRealm();
        // This viewer may read the Redirects list (the harness's `ux.author` admits site editors), so the rail shows its leaf.
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));

        $figures = $this->figures();

        $this->assertArrayHasKey('beam-ux-redirect.index', $leaves, 'the tenant realm binds the Redirects list leaf');
        $this->assertArrayHasKey('beam-ux-site-map.index', $leaves, 'the tenant realm binds the Site map leaf');
        $this->assertSame(3, $figures['redirects']['value']);
        $this->assertSame($leaves['beam-ux-redirect.index'], $figures['redirects']['href'], 'it links to the Redirects list leaf');
        $this->assertSame($leaves['beam-ux-site-map.index'], $figures['published']['href'], 'the page figures link to the Site map leaf');
    }

    public function test_a_tenant_principal_who_may_not_read_the_redirects_list_still_gets_the_figure_linked_to_the_site_map(): void
    {
        $this->seedTenantSite();
        $this->seedTenantRedirects();
        $leaves = $this->servedInTenantRealm();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));
        // As on the tower starter: `ux.author` is central-only, so a tenant principal never holds it, and the Redirects list
        // (policy `ux.author`) refuses it, so the rail shows no Redirects leaf. The figure does not read that list: it
        // still draws.
        Gate::define('ux.author', fn (): bool => false);

        $figures = $this->figures();

        $this->assertArrayHasKey('beam-ux-redirect.index', $leaves, 'the Redirects list leaf exists in the realm; this viewer may not follow it');
        $this->assertArrayHasKey('beam-ux-site-map.index', $leaves, 'the tenant realm binds the Site map leaf');
        $this->assertSame(3, $figures['redirects']['value']);
        $this->assertSame('Redirects to your pages', $figures['redirects']['label']);
        $this->assertSame($leaves['beam-ux-site-map.index'], $figures['redirects']['href'], 'with no Redirects leaf on the rail, it links to the Site map leaf');
    }

    public function test_a_viewer_refused_the_site_map_draws_no_redirects_figure_and_never_falls_back_to_the_redirects_list(): void
    {
        $this->seedTenantSite();
        $this->seedTenantRedirects();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));
        // The area admits this site editor, and the Redirects list would too (`ux.author`), but the entries model's own
        // policy refuses the Site map. Tenant scope counts only from the Site map, so it draws no Redirects figure at all.
        Gate::policy(BeamUxEntry::class, RefusingEntriesPolicy::class);

        $figures = $this->figures();

        $this->assertArrayNotHasKey('redirects', $figures, 'no Site map, no Redirects figure: an honest absence, not the list');
        $this->assertArrayNotHasKey('published', $figures);
        $this->assertSame(5, $this->listed(SiteArea::REDIRECTS), 'the Redirects list was readable all along');
    }

    public function test_a_site_with_no_pages_counts_zero_redirects(): void
    {
        // A Redirect, but no page for it to reach: the Site map lists no page, so the figure is 0, not absent.
        BeamUxRedirect::withoutEvents(fn () => BeamUxRedirect::create(['realm' => 'site', 'path' => '/old', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/new']));
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));

        $figures = $this->figures();

        $this->assertSame(0, $figures['redirects']['value']);
        $this->assertSame(0, $figures['published']['value']);
    }

    public function test_each_tenant_counts_only_the_redirects_on_its_own_connection(): void
    {
        // acme, on the default connection: three Redirects that lead to its pages.
        $this->seedTenantSite();
        $this->seedTenantRedirects();
        $this->actingAs($this->reader(7, ['analytics.view'], editor: true));
        $this->assertSame(3, $this->figures()['redirects']['value'], 'acme');

        // globex, on a schema of its own (as tenancy switches the default connection): an /about page like acme's, and a
        // contact page with one Redirect to it. acme's /old-about and /older-about would reach globex's /about if any
        // Redirect read crossed tenants, so a leak would count at least 3, not 1.
        config()->set('database.connections.tenant_globex', ['driver' => 'sqlite', 'database' => ':memory:']);
        config()->set('database.default', 'tenant_globex');
        DB::purge('tenant_globex');
        // A tenant is resolved per request, and every request starts with fresh scoped instances. This harness serves its
        // requests in one app, so it forgets them here as a new request would; otherwise the Site map index would keep
        // acme's per-request Redirect walk.
        $this->app->forgetScopedInstances();
        $this->createEntriesTable();
        (include dirname(__DIR__, 2).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->livePage('about');
        $this->livePage('contact');
        BeamUxRedirect::withoutEvents(fn () => BeamUxRedirect::create(['realm' => 'site', 'path' => '/old-contact', 'match' => 'exact', 'target_kind' => 'path', 'target_path' => '/contact']));

        $this->assertSame(1, $this->figures()['redirects']['value'], 'globex sees only its own Redirect');

        // Back on acme's connection, acme's count is untouched by globex's rows.
        config()->set('database.default', 'testing');
        $this->app->forgetScopedInstances();
        $this->assertSame(3, $this->figures()['redirects']['value'], 'acme again');
    }

    public function test_a_reader_who_cannot_edit_the_site_is_refused_the_area(): void
    {
        $this->seedTenantSite();
        // analytics.view but NOT a site editor: fails the tenant area gate (site.edit), so the area withholds entirely.
        $this->actingAs($this->reader(9, ['analytics.view']));

        $this->getJson('frame/resources/analytics-site')->assertForbidden();
    }
}

/** The entries model's read policy for the tenant Site map: a site editor may list, others may not. */
class TenantEntriesPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::allows(SiteAreaAbility::TENANT_ABILITY);
    }

    public function view(User $user): bool
    {
        return Gate::allows(SiteAreaAbility::TENANT_ABILITY);
    }
}

/** analytics-area 29: an entries read policy that refuses the Site map to everyone. */
class RefusingEntriesPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user): bool
    {
        return false;
    }
}
