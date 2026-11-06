<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Sitemap\Tags\Url;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Data\SiteMapRowData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Sitemap\SitemapEligibility;
use Splicewire\Beam\Ux\Sitemap\SiteMapIndex;

/**
 * owner-review-20261102 research 37, 37-T1: the Site map's read projection, one row per page. Its search verdict is the
 * sitemap's own (the shared {@see SitemapEligibility} that {@see EntrySitemapSource} runs), never the diagnostic's
 * route × published × entitled shortcut; its File column is central-only whatever DiagnosticsAbility says (D-37-d);
 * its inbound-redirect summary flags only what fails a guest (D-37-g (b)).
 */
class SiteMapRowDataTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_releases_table.php.stub')->up();
        // The File column reads MirrorGitStatus, whose GitRepoRegistrar records the repo a mirror file sits in.
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        (require $core.'/database/migrations/shared/create_beam_git_repos_table.php.stub')->up();
        config()->set('app.url', 'https://example.test');
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    public function test_it_is_declared_read_only_on_the_entries_model_with_no_write_shape(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('beam-ux-site-map');

        $this->assertNotNull($resource, 'the beam-ux-site-map resource is registered by discovery');
        $this->assertSame(SiteMapRowData::class, $resource->data);
        $this->assertTrue($resource->readOnly);
    }

    public function test_the_mounted_index_serves_one_row_per_page_to_an_entries_reader(): void
    {
        $about = $this->livePage('about');
        BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site']);
        \Splicewire\Beam\Facades\Particle::mount('beam-ux-site-map', 'beam-ux-site-map')->only(['index']);
        Gate::policy(BeamUxEntry::class, SiteMapReaderPolicy::class);
        $this->actingAs((new User)->forceFill(['id' => 1]));

        $rows = $this->getJson('/beam-ux-site-map')->assertOk()->json('data');

        $this->assertSame([$about->id], array_column($rows, 'id'), 'pages only: no realm root, no component');
        $this->assertSame('listed', $rows[0]['search']['reason']);
        $this->assertSame('/about', $rows[0]['url']);
        $this->assertSame(0, $rows[0]['redirects']['count']);
    }

    public function test_the_search_verdict_is_the_sitemaps_own_for_a_hidden_ancestor_an_unlistable_page_and_an_unpublished_one(): void
    {
        $about = $this->livePage('about');
        $guides = $this->livePage('guides');
        $start = $this->livePage('start', $guides);
        $unlisted = $this->livePage('beta');
        $unlisted->forceFill(['traverse' => ['root']])->save();
        $draft = BeamUxEntry::create(['slug' => 'draft', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'draft']);
        $component = BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'format' => 'tsx', 'realm' => 'site']);

        // `guides` and `draft` are hidden; `start` is published itself but sits under `guides` (ADR-0236 d8).
        $hidden = [$guides->id, $draft->id];
        $this->app->bind(EntryPublishGate::class, fn () => new class($hidden) implements EntryPublishGate
        {
            public function __construct(private array $hidden) {}

            public function isPublished(BeamUxEntry $entry): bool
            {
                return ! in_array($entry->id, $this->hidden, true);
            }
        });

        $rows = $this->rows();

        $this->assertSame(['listed', 'unpublished', 'hidden-ancestor', 'not-listable', 'unpublished'], [
            $rows[$about->id]->search->reason, $rows[$guides->id]->search->reason, $rows[$start->id]->search->reason,
            $rows[$unlisted->id]->search->reason, $rows[$draft->id]->search->reason,
        ]);
        $this->assertArrayNotHasKey($component->id, $rows, 'one row per page: a component is a Site part, not a page');

        // The diagnostic's shortcut calls `start` and `beta` indexed; the sitemap does not list them, and neither does the map.
        $this->assertTrue(\Splicewire\Beam\Ux\Data\SitemapHealthRowData::project($start->fresh())->indexed);
        $this->assertTrue(\Splicewire\Beam\Ux\Data\SitemapHealthRowData::project($unlisted->fresh())->indexed);

        // Parity: a row is listed exactly when the real feed yields its URL.
        $feed = array_map(fn (Url $url) => $url->url, iterator_to_array(app(EntrySitemapSource::class)->urls(), false));
        foreach ($rows as $row) {
            $this->assertSame(in_array('https://example.test'.$row->url, $feed, true), $row->search->listed,
                "{$row->url}: the map's verdict ({$row->search->reason}) matches the sitemap feed");
        }
    }

    public function test_it_carries_the_tree_and_the_publication_pins(): void
    {
        $guides = $this->livePage('guides');
        $start = $this->livePage('start', $guides);
        $pub = app(EntryPublication::class);
        $pub->recordDraft($start->fresh(), [$this->block('section')]);

        $row = $this->rows()[$start->id];

        $this->assertSame($guides->id, $row->parentId);
        $this->assertSame('start', $row->segment);
        $this->assertSame('/guides/start', $row->url);
        $state = $pub->state($start->fresh());
        $this->assertNotNull($state->publishedReadable);
        $this->assertSame($state->publishedReadable, $row->publishedReadable, 'readers see the published version');
        $this->assertSame($state->headReadable, $row->headReadable, 'the draft is the newer one');
        $this->assertNotSame($row->publishedReadable, $row->headReadable);
        $this->assertTrue($row->draftPending);
        $this->assertFalse($this->rows()[$guides->id]->draftPending);
    }

    public function test_the_file_column_is_central_only_whatever_the_diagnostics_ability_says(): void
    {
        $this->mirrorInsideAGitRepo();
        $page = $this->livePage('about');
        Gate::define('beam-ux-entry.update', fn ($user = null) => true);
        $this->actAs(new User);

        $central = $this->rows()[$page->id]->file;
        $this->assertNotNull($central, 'central, and the reader holds DiagnosticsAbility: the File column is drawn');
        $this->assertNotSame('', $central->state);

        // The same reader, the same rights, on a tenant's connection: no File column (D-37-d).
        $this->app->instance(TenancyContext::class, $this->tenancy(active: true));
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Manifest\EntryResidency::class);
        $this->assertNull($this->rows()[$page->id]->file);
    }

    public function test_the_file_column_is_absent_for_a_reader_without_the_diagnostics_ability(): void
    {
        $page = $this->livePage('about');
        Gate::define('beam-ux-entry.update', fn ($user = null) => false);
        $this->actAs(new User);

        $this->assertNull($this->rows()[$page->id]->file);
    }

    public function test_a_file_repair_names_the_member_of_the_latest_published_release_or_why_none_is_eligible(): void
    {
        $this->mirrorInsideAGitRepo();
        $page = $this->livePage('about');
        Gate::define('beam-ux-entry.update', fn ($user = null) => true);
        $this->actAs(new User);

        $this->assertSame('no-release', $this->rows()[$page->id]->file->repairBlocked);
        $this->assertNull($this->rows()[$page->id]->file->repairMemberId);

        $old = $this->release('published', now()->subDay());
        $this->member($old, $page, (string) Str::uuid());
        $this->assertSame('pin-moved', $this->rows()[$page->id]->file->repairBlocked,
            'the newest published member froze another version than the one readers see');

        $current = $this->release('published', now());
        $member = $this->member($current, $page, (string) $page->fresh()->published_version);
        $this->member($this->release('draft', now()->addMinute()), $page, (string) $page->fresh()->published_version);

        $file = $this->rows()[$page->id]->file;
        $this->assertNull($file->repairBlocked);
        $this->assertSame($member, $file->repairMemberId, 'a draft Release is not what a repair addresses');
        $this->assertSame($current, $file->repairReleaseId);
    }

    public function test_inbound_redirects_flag_only_what_fails_a_guest_and_count_a_chains_hops_as_information(): void
    {
        $pricing = $this->livePage('pricing');
        $about = $this->livePage('about');
        $this->redirect('/plans', 'page', ['target_entry_id' => $pricing->id]);
        $this->redirect('/old-plans', 'path', ['target_path' => '/plans']);
        $this->redirect('/older-plans', 'path', ['target_path' => '/old-plans']);

        $pricingRow = $this->rows()[$pricing->id];
        $this->assertSame(3, $pricingRow->redirects->count, 'one direct, two through a chain');
        $this->assertSame(3, $pricingRow->redirects->maxHops);
        $this->assertNull($pricingRow->redirects->attention, 'a chain that still answers is informational (D-37-g (b))');
        $this->assertSame(['/older-plans', '/old-plans', '/plans'], array_map(fn ($in) => $in->path, $pricingRow->redirects->inbound));

        // A Redirect to `about` whose path a loop re-enters, and one that dead-ends at a hidden page.
        $this->redirect('/team', 'path', ['target_path' => '/about']);
        $this->redirect('/loop-a', 'path', ['target_path' => '/loop-b']);
        $this->redirect('/loop-b', 'path', ['target_path' => '/loop-a']);
        $this->assertNull($this->rows()[$about->id]->redirects->attention);
        $this->assertSame(1, $this->rows()[$about->id]->redirects->count, 'the loop reaches no page, so no page row carries it');

        $about->forceFill(['workflow_marking' => 'review'])->save();
        $this->app->bind(EntryPublishGate::class, fn () => new class($about->id) implements EntryPublishGate
        {
            public function __construct(private string $hidden) {}

            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->id !== $this->hidden;
            }
        });
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Http\PublicEntryGate::class);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Redirect\RedirectResolver::class);
        $this->assertSame('dead-end', $this->rows()[$about->id]->redirects->attention);
    }

    /** @return array<string, SiteMapRowData> */
    private function rows(): array
    {
        $this->app->forgetInstance(SiteMapIndex::class);
        $this->app->forgetInstance(SitemapEligibility::class);

        return SiteMapRowData::scope(BeamUxEntry::query())->get()
            ->mapWithKeys(fn (BeamUxEntry $entry) => [$entry->id => SiteMapRowData::project($entry)])
            ->filter(fn ($row) => $row !== null)->all();
    }

    private function livePage(string $segment, ?BeamUxEntry $parent = null): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment, 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => ($parent ?? BeamUxEntry::rootFor())->id, 'segment' => $segment]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->block('div')])->headVersion);

        return $page->fresh();
    }

    private function block(string $name): array
    {
        return ['kind' => 'block', 'name' => $name, 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []];
    }

    private function redirect(string $path, string $kind, array $columns): void
    {
        DB::table('beam_ux_redirects')->insert($columns + [
            'id' => (string) Str::uuid(), 'realm' => 'site', 'path' => $path, 'match' => 'exact', 'target_kind' => $kind,
            'permanent' => true, 'origin' => 'author', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function release(string $status, \DateTimeInterface $at): string
    {
        $id = (string) Str::uuid();
        DB::table('beam_ux_releases')->insert(['id' => $id, 'title' => 'R '.$status, 'realm' => 'site', 'created_by' => '1',
            'edited_by' => '1', 'status' => $status, 'created_at' => $at, 'updated_at' => $at]);

        return $id;
    }

    private function member(string $release, BeamUxEntry $entry, string $version): string
    {
        $id = (string) Str::uuid();
        DB::table('beam_ux_release_members')->insert(['id' => $id, 'release_id' => $release, 'entry_id' => $entry->id,
            'version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * The mirror inside a real git repository, as on a fresh clone. A worktree's `.git` is a file, which
     * `GitRepoRegistrar::findGitRoot()` (an `is_dir` test) does not see, so a run from a worktree never reached the
     * registrar while a clone did (orch2's tots run, 2026-11-06).
     */
    private function mirrorInsideAGitRepo(): void
    {
        $root = sys_get_temp_dir().'/beamux-site-map-mirror-'.uniqid();
        mkdir($root.'/.git', 0777, true);
        config(['filesystems.disks.component-mirror' => ['driver' => 'local', 'root' => $root]]);
        \Illuminate\Support\Facades\Storage::forgetDisk('component-mirror');
        $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    }

    private function actAs(User $user): void
    {
        $this->app['request']->setUserResolver(fn () => $user);
    }

    private function tenancy(bool $active): TenancyContext
    {
        return new class($active) implements TenancyContext
        {
            public function __construct(private bool $active) {}

            public function isMultiTenant(): bool
            {
                return true;
            }

            public function isActive(): bool
            {
                return $this->active;
            }

            public function currentTenant(): ?object
            {
                return null;
            }

            public function currentTenantId(): int|string|null
            {
                return $this->active ? 'acme' : null;
            }

            public function centralConnection(): ?string
            {
                return null;
            }
        };
    }
}

/** An entries reader: the model policy's `viewAny`, which the Site map shares with `beam-ux-entry`. */
class SiteMapReaderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }
}
