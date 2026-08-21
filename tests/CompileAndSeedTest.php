<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;
use Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ADR-0209 §7 (compile on save, no client fallback) and ADR-0210 (a contribution is a seed row).
 *
 *  - the artifact key IS the particle version, so a changed body makes the old artifact **absent**
 *    rather than out of date — that is what lets one existence check answer "compiled, and current?";
 *  - the doctor **fails** on a page with no artifact, because with no fallback it is a 404 waiting to
 *    happen, and **warns** on a page whose contributed endpoint nothing mounts (ADR-0210 §6);
 *  - the seeder provisions the realm root ADR-0209 §9 requires and the docs subtree, and is
 *    **create-only**: a re-seed after the site has re-worded or re-rooted a page changes nothing;
 *  - the docs root's segment is seeded from config as an INITIAL value and re-rooting is a row edit.
 */
class CompileAndSeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts', 'beam.ux.seed_nav' => false]);

        $this->app->extend(
            StorageDriverResolver::class,
            fn () => (new StorageDriverResolver)
                ->register(StorageDriverResolver::DEFAULT, new RecordingDriver),
        );

        FakeCompiler::$fails = false;
        $this->app->singleton(EntryBodyCompiler::class, fn () => new FakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
    }

    protected function tearDown(): void
    {
        FakeCompiler::$fails = false;
        parent::tearDown();
    }

    public function test_an_artifact_is_keyed_by_version_so_a_changed_body_is_absent_not_stale(): void
    {
        $entry = $this->page('guide');
        $compile = $this->app->make(CompileEntryBody::class);
        $artifacts = $compile->artifacts();

        $compile->forEntry($entry, '# one');
        $this->assertTrue($artifacts->has($entry));
        $first = $artifacts->path($entry);

        // Touching the row moves the fallback version key, which is what a real particle write would do
        // through `head_version`. The OLD artifact is not "stale at the same address" — it is a
        // different address, and this one simply is not there.
        $entry->forceFill(['updated_at' => now()->addMinute()])->save();
        $entry = $entry->fresh();

        $this->assertFalse($artifacts->has($entry));
        $this->assertNotSame($first, $artifacts->path($entry));
    }

    public function test_compiling_skips_a_current_artifact_and_prunes_older_versions(): void
    {
        $entry = $this->page('guide');
        $compile = $this->app->make(CompileEntryBody::class);

        $compile->forEntry($entry, '# one');
        $path = $compile->artifacts()->path($entry);

        // A second call with different source is a no-op — the artifact is already current for this
        // version, and the version is what identifies the body.
        $compile->forEntry($entry, '# two');
        $this->assertStringContainsString('# one', Storage::disk('artifacts')->get($path));

        // Forcing recompiles in place and leaves exactly one artifact for the entry.
        $compile->forEntry($entry, '# two', force: true);
        $this->assertStringContainsString('# two', Storage::disk('artifacts')->get($path));
        $this->assertCount(1, Storage::disk('artifacts')->files('beam-ux/artifacts/'.$entry->getKey()));
    }

    public function test_the_backfill_command_compiles_pending_pages_and_fails_on_a_broken_body(): void
    {
        $this->page('guide');

        $this->artisan('splicewire:beam:ux:compile')->assertSuccessful();

        FakeCompiler::$fails = true;
        $this->page('broken');

        $this->artisan('splicewire:beam:ux:compile')->assertFailed();
    }

    public function test_the_doctor_fails_on_a_missing_artifact_and_warns_on_an_orphaned_endpoint(): void
    {
        $entry = $this->page('mcp', '<ManifestTable endpoint="/beam/mcp/manifest.json" />');

        $findings = $this->audit();
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('mcp', $findings[0]->detail);

        // Compile it, with a body naming an endpoint nothing mounts: the page is fine, its contributor
        // is gone. ADR-0210 §6 keeps the page 200ing, so the doctor is the ONLY thing that says so.
        $this->app->make(CompileEntryBody::class)->forEntry($entry);

        $findings = $this->audit();
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('/beam/mcp/manifest.json', $findings[1]->detail);

        // Mount the contributor's route and the warning goes away.
        Route::get('beam/mcp/manifest.json', fn () => ['items' => []]);
        $this->assertSame(DoctorStatus::Pass, $this->audit()[1]->status);
    }

    /**
     * A realm root has no body and never will (ADR-0209 §9), so it is not a missing artifact. The
     * compile command learned this at ticket 07; this audit reads the same table for the same property
     * and did not, so `splicewire:beam:doctor` reported a BLOCKING error naming all four realm roots on
     * every correctly-seeded host — observed on `splicewire/www` at ticket 08.
     */
    public function test_the_doctor_does_not_count_bodyless_structural_nodes_as_missing_artifacts(): void
    {
        BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE);

        $this->assertSame(DoctorStatus::Pass, $this->audit()[0]->status);

        // An ADDRESSABLE page with no artifact is still a failure — that one really does 404.
        $this->page('guide', '# Guide');

        $this->assertSame(DoctorStatus::Fail, $this->audit()[0]->status);
    }

    public function test_the_seeder_provisions_the_realm_root_and_the_docs_subtree(): void
    {
        $this->seed(BeamUxSeeder::class);

        $root = BeamUxEntry::query()->where('namespace', 'realms')->where('slug', 'site')->first();
        $this->assertNotNull($root, 'ADR-0209 §9: the root is seeded, never created by a GET');

        $docs = BeamUxEntry::query()->where('slug', 'docs')->firstOrFail();
        $this->assertSame('/docs', $docs->segment);
        $this->assertSame($root->getKey(), $docs->parent_id);

        $api = BeamUxEntry::query()->where('slug', 'docs-api')->firstOrFail();
        $this->assertSame($docs->getKey(), $api->parent_id);
        $this->assertSame('/docs/api', $api->url());

        // The page points at beam core's own artifact route, interpolated at seed time because a body
        // cannot call route() (ticket 21 / ADR-0211).
        $source = $this->app->make(CompileEntryBody::class)->sourceFor($api);
        $this->assertStringContainsString('/beam/openapi.yaml', (string) $source);
    }

    public function test_re_seeding_never_clobbers_what_the_site_has_edited(): void
    {
        $this->seed(BeamUxSeeder::class);

        // The site re-roots its docs and re-titles the reference page — a data edit on rows it owns,
        // which is the entire reason ticket 02 ruled docs a containment subtree rather than config.
        BeamUxEntry::query()->where('slug', 'docs')->update(['segment' => '/beam/docs']);
        BeamUxEntry::query()->where('slug', 'docs-api')->update(['title' => 'Our API']);

        $this->seed(BeamUxSeeder::class);

        $this->assertSame('/beam/docs', BeamUxEntry::query()->where('slug', 'docs')->value('segment'));
        $this->assertSame('Our API', BeamUxEntry::query()->where('slug', 'docs-api')->value('title'));
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'docs')->count());
    }

    public function test_the_docs_seed_can_be_gated_off_while_the_realm_root_still_lands(): void
    {
        config(['beam.ux.docs.seed' => false]);

        $this->seed(BeamUxSeeder::class);

        $this->assertNotNull(BeamUxEntry::query()->where('namespace', 'realms')->where('slug', 'site')->first());
        $this->assertNull(BeamUxEntry::query()->where('slug', 'docs')->first());
    }

    /** @return array<int, \Rushing\Doctor\Finding> */
    private function audit(): array
    {
        return $this->app->make(BeamUxArtifactAudit::class)->run();
    }

    /** A page with a body persisted through the storage driver, the way any real producer leaves one. */
    private function page(string $slug, string $source = '# page'): BeamUxEntry
    {
        $entry = BeamUxEntry::create([
            'slug' => $slug,
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
            'segment' => $slug,
        ]);

        $written = $this->app->make(StorageDriverResolver::class)
            ->resolve($entry)
            ->write('', $entry->codec()->encode($source), $entry->namespace);

        $entry->particle_id = $written->key;
        $entry->save();

        return $entry->fresh();
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false);
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
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
            $table->unique(['parent_id', 'segment']);
        });
    }
}
