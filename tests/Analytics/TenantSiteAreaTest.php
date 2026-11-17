<?php

namespace Splicewire\Beam\Ux\Tests\Analytics;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Analytics\SiteArea;
use Splicewire\Beam\Ux\Analytics\SiteAreaAbility;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
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
