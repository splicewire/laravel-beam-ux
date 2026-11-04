<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\JsonDoc\TsxLens;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * An entry registered from disk is BORN PUBLISHED (ADR-0225 decision 2), like a seeded page.
 *
 * `RegisterEntriesFromDisk` (register-from-disk, ADR-0164) is the largest pin-less population on a fresh install: one
 * www `splicewire:beam:seed` inserted 33 entry rows and 2 version rows (launch row qitem-20261016215906-483ede25;
 * ticket 00 nomination 50c25971). It now registers, writes and pins in one transaction, a re-scan repairs a pin-less row
 * that has no history (never publishing a history-bearing draft), and failures surface.
 */
class DiskEntriesPublicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // The default WriteGate delegates to the Laravel gate; a host grants the console import its writes.
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);

        Storage::fake('artifacts');
        config(['beam.ux.compile.disk' => 'artifacts']);
        $this->app->singleton(EntryBodyCompiler::class, fn () => new DiskFakeCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(EntryArtifactStore::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);

        $this->root = sys_get_temp_dir().'/beamux-disk-pub-'.uniqid();
        mkdir($this->root.'/page', 0777, true);
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n# Guide\n\nRegistered from disk.\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/page/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root.'/elsewhere');
        @unlink($this->root.'/page/guide.mdx');
        @rmdir($this->root.'/page');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_baseline_keeps_an_imported_legacy_disk_path_without_a_second_source(): void
    {
        $disk = Storage::fake('legacy-source');
        $disk->put('access.mdx', "# Access\n");
        $scanner = app(RegisterEntriesFromDisk::class);
        $scanner->scan($disk->path(''), default: UxType::Page);
        $entry = BeamUxEntry::query()->where('slug', 'access')->firstOrFail();
        $this->assertSame('disk:access.mdx', $entry->origin);

        // Reproduce an imported pre-L3 row: body present, no history or publication pin.
        $entry->forceFill(['published_version' => null])->save();
        \DB::table(Beam::table('versions'))->where('versionable_id', $entry->particle_id)->delete();
        $this->app->instance(PlacedDiskMirror::class, new PlacedDiskMirror($disk));
        $this->app->forgetInstance(EntryPublication::class);
        app(EntryPublication::class)->baseline($entry->fresh());

        $this->assertSame(['access.mdx'], $disk->allFiles(), 'baseline created a second recognized source');
        $this->assertSame("# Access\n", $disk->get('access.mdx'));
        $this->assertNotNull($entry->fresh()->published_version);
        $plan = $scanner->plan($disk->path(''), UxType::Page);
        $this->assertCount(1, $plan['matched']);
        $this->assertSame([], $plan['unregistered']);
    }

    private function guide(): ?BeamUxEntry
    {
        return BeamUxEntry::query()->where('slug', 'guide')->whereNull('namespace')->first();
    }

    private function versionRows(BeamUxEntry $entry): int
    {
        return (int) \DB::table(Beam::table('versions'))->where('versionable_id', (string) $entry->fresh()->particle_id)->count();
    }

    public function test_a_registered_tsx_page_is_lowered_to_a_jsondoc_through_the_lens(): void
    {
        // EP-06 d9: a disk-registered tsx page stores a JsonDoc LOWERED through the Node TSX Lens, not raw
        // source (the mechanism this method used to defer). A fake lens stands in for the Node run (proven
        // end-to-end on tots); a fresh registration has no Working Head, so the rematch mints fresh ids.
        $this->app->bind(TsxLens::class, fn () => new class implements TsxLens
        {
            public function lower(string $source): array
            {
                return [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From disk']], 'dynamic' => false]];
            }
        });
        file_put_contents($this->root.'/page/home.tsx', 'export default () => <section>From disk</section>');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = BeamUxEntry::query()->where('slug', 'home')->whereNull('namespace')->firstOrFail();
        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)->body;

        $this->assertSame('section', $body[0]['name']);
        $this->assertArrayHasKey('id', $body[0]); // a stable id minted by the rematch, not raw source

        @unlink($this->root.'/page/home.tsx');
    }

    public function test_a_registered_mdx_page_is_lowered_through_the_mdx_lens_on_a_host_that_holds_mdx_as_blocks(): void
    {
        // ADR-0233 d2, per host: registration from disk reads the file's BODY through the MDX Lens (its frontmatter
        // is read into the entry's own fields, never the lens), and the rematch mints fresh ids.
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
        $lens = new class implements \Splicewire\Beam\Mdx\Lens\MdxLens
        {
            /** @var list<string> */
            public array $bodies = [];

            public function read(string $body, array $context = []): array
            {
                $this->bodies[] = $body;

                return ['doc' => [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From MDX']], 'dynamic' => false]], 'meta' => null];
            }

            public function print(array $doc): string
            {
                return "## From MDX\n";
            }
        };
        $this->app->instance(\Splicewire\Beam\Mdx\Lens\MdxLens::class, $lens);
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n## From MDX\n");

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = BeamUxEntry::query()->where('slug', 'guide')->whereNull('namespace')->firstOrFail();
        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)->body;

        $this->assertSame('h2', $body[0]['name']);
        $this->assertArrayHasKey('id', $body[0]);
        $this->assertCount(1, $lens->bodies);
        $this->assertStringNotContainsString('title: Guide', $lens->bodies[0], 'the lens reads the body, never the frontmatter');
        $this->assertSame('Guide', $entry->title);

        @unlink($this->root.'/page/guide.mdx');
    }

    /**
     * ADR-0233 d9, the disk half of EP-42 S1: a package that ships its pages as a docs source (tower's `docs-bundle`,
     * `package: splicewire/tower`) has them registered from disk. Once the host turns the gate on, the next registration
     * re-asserts a pristine page AS BLOCKS. It used to encode the file through the codec, which does not lower, so it stayed
     * source; and because the hash compares printed text, an already-canonical file (this one) read as unchanged. Found by
     * the EP-42 H2 receipt: the flagship's 8 tower pages kept source after the re-seed.
     */
    public function test_a_pristine_package_page_registered_from_disk_holds_blocks_once_the_host_holds_blocks(): void
    {
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n## From MDX\n");
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $this->assertFalse(array_is_list($this->publishedBody($this->guide())), 'source, while the host keeps MDX as source');

        $this->holdBlocks();
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');

        $this->assertSame('h2', $this->publishedBody($this->guide())[0]['name'], 'pristine: re-asserted, as blocks');
        $this->assertContains('page/guide.mdx', $result['reasserted']);
        $this->assertSame('package:acme/docs', $this->guide()->origin);
    }

    public function test_a_site_edited_package_page_keeps_the_site_s_words_when_the_host_holds_blocks(): void
    {
        // A site's edit is published as an author's write, which records activity.
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        (require $core.'/database/migrations/shared/create_activity_log_table.php.stub')->up();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n## From MDX\n");
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $pub = app(EntryPublication::class);
        $edited = \Splicewire\Beam\Mdx\MdxBody::encode("---\ntitle: Guide\n---\n\nOur words.\n");
        $pub->publishVersion($this->guide(), $pub->recordDraft($this->guide(), $edited)->headVersion);

        $this->holdBlocks();
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');

        $this->assertStringContainsString('Our words.', \Splicewire\Beam\Mdx\MdxBody::decode($this->publishedBody($this->guide())), 'edited: kept');
        $this->assertNotContains('page/guide.mdx', $result['reasserted']);
    }

    /**
     * Registry-docs ticket 09/10: a page FIRST registered on a host that already holds MDX as blocks is pristine at birth.
     * Registration stamped the raw file and then stored its lowering, whose print drops the frontmatter and reflows a
     * hard-wrapped paragraph, so the row read edited from the start and never re-asserted (69 rows on www, 2026-11-04).
     */
    public function test_a_disk_page_first_registered_as_blocks_is_pristine_re_asserts_and_keeps_a_site_edit(): void
    {
        $this->assertFirstRegistrationAsBlocksIsPristine(null, 'disk:page/guide.mdx');
    }

    public function test_a_package_page_first_registered_as_blocks_is_pristine_re_asserts_and_keeps_a_site_edit(): void
    {
        $this->assertFirstRegistrationAsBlocksIsPristine('acme/docs', 'package:acme/docs');
    }

    private function assertFirstRegistrationAsBlocksIsPristine(?string $package, string $origin): void
    {
        // A host that holds blocks carries the page-settings columns (the excerpt lands in seo_description).
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $this->holdReflowingBlocks();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nA paragraph the author\nhard-wrapped at a narrow width.\n\nA second paragraph.\n");
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: $package);
        $reasserter = app(Reasserter::class);

        $this->assertSame($origin, $this->guide()->origin);
        $this->assertTrue($reasserter->isPristine($this->guide()), 'born pristine: the asserted hash is the stored form');
        $this->assertFalse($this->diverged($this->guide()), 'docs.diverged is silent on a freshly registered row');

        // A changed file reaches the row on the next scan.
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nA paragraph the author\nrevised on disk.\n\nA second paragraph.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: $package);
        $this->assertContains('page/guide.mdx', $result['reasserted']);
        $this->assertStringContainsString('the author revised on disk.', (string) $reasserter->currentBody($this->guide()));
        $this->assertTrue($reasserter->isPristine($this->guide()));

        // A site edit is kept, and docs.diverged names it.
        $this->authorEdit('Our words.');
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nThe origin moved on again.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: $package);
        $this->assertNotContains('page/guide.mdx', $result['reasserted']);
        $this->assertSame("Our words.\n", $reasserter->currentBody($this->guide()), 'edited: kept');
        $this->assertTrue($this->diverged($this->guide()));
    }

    // ── The one-time re-stamp (registry-docs ticket 10 item 3) ──────────────────────────────────────────────────────

    private const WRAPPED = "---\ntitle: Guide\nexcerpt: A guide\n---\n\nA paragraph the author\nhard-wrapped at a narrow width.\n";

    /** A row as register-from-disk created it before ticket 10: its body lowered, its hash taken over the raw file. */
    private function bornUnstamped(?string $package = null, string $source = self::WRAPPED): BeamUxEntry
    {
        if (! Schema::hasColumn('beam_ux_entries', 'seo_description')) {
            (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        }
        $this->holdReflowingBlocks();
        file_put_contents($this->root.'/page/guide.mdx', $source);
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: $package);
        $entry = $this->guide();
        $old = \Splicewire\Beam\Ux\Provenance\Provenance::hash($entry->title, \Splicewire\Beam\Ux\Provenance\Provenance::asStored($entry->codec(), $source));
        \DB::table('beam_ux_entries')->where('id', $entry->id)->update(['asserted_hash' => $old]);
        $this->assertFalse(app(Reasserter::class)->isPristine($this->guide()), 'the fixture reproduces the defect');

        return $this->guide();
    }

    /** @return array<string, list<array<string, mixed>>> every row of every table the re-stamp could touch */
    private function snapshot(): array
    {
        $tables = ['beam_ux_entries', Beam::table('particles'), Beam::table('versions')];
        if (Schema::hasTable('activity_log')) {
            $tables[] = 'activity_log';
        }

        return collect($tables)->mapWithKeys(fn (string $t) => [$t => \DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    /** Run the re-stamp over the fixture root and assert it succeeded; returns its whole output, unwrapped. */
    private function restamp(bool $apply = false, array $options = []): string
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('splicewire:beam:ux:register-from-disk', $options ?: ['path' => $this->root, '--restamp' => true] + ($apply ? ['--apply' => true] : []));
        $output = (string) preg_replace('/\s+/', ' ', \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame(0, $exit, $output);

        return $output;
    }

    public function test_restamp_dry_run_lists_the_row_and_writes_nothing_then_apply_makes_it_pristine_and_a_reassert_is_a_no_op(): void
    {
        $this->bornUnstamped('acme/docs');
        $before = $this->snapshot();

        $output = $this->restamp();
        $this->assertStringContainsString('would re-stamp', $output);
        $this->assertStringContainsString('1 would re-stamp · 0 left alone (0 pristine)', $output);
        $this->assertSame($before, $this->snapshot(), 'a dry run is byte-identical');

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('1 re-stamped of 1 that qualified', $output);
        $entry = $this->guide();
        $this->assertTrue(app(Reasserter::class)->isPristine($entry), 'pristine after --apply');
        $after = $this->snapshot();
        $before['beam_ux_entries'][0]['asserted_hash'] = $entry->asserted_hash;
        $this->assertSame($before, $after, 'nothing but asserted_hash moved: no body, version, origin, placement or timestamp');

        // A re-assert over the unchanged file is a no-op, and a second re-stamp finds nothing to do.
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $this->assertSame([], $result['reasserted']);
        $this->assertSame($after, $this->snapshot());
        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('0 re-stamped of 0', $output);
        $this->assertStringContainsString('1 left alone (1 pristine)', $output);

        // The flow the defect froze: a changed file now reaches the row.
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nRevised on disk.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $this->assertSame(['page/guide.mdx'], $result['reasserted']);
        $this->assertSame("Revised on disk.\n", app(Reasserter::class)->currentBody($this->guide()));
    }

    public function test_restamp_refuses_a_pristine_row(): void
    {
        $this->holdReflowingBlocks();
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        file_put_contents($this->root.'/page/guide.mdx', self::WRAPPED);
        app(RegisterEntriesFromDisk::class)->scan($this->root);
        $before = $this->snapshot();

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('1 left alone (1 pristine)', $output);
        $this->assertStringContainsString('0 re-stamped of 0', $output);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_restamp_refuses_a_row_whose_file_moved_on_since_it_was_asserted(): void
    {
        $this->bornUnstamped();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nA later file.\n");
        $before = $this->snapshot();

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('asserted hash is not this file', $output);
        $this->assertStringContainsString('0 re-stamped of 0', $output);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_restamp_refuses_a_host_edit_and_keeps_it(): void
    {
        $this->bornUnstamped();
        $old = $this->guide()->asserted_hash;
        $this->authorEdit('Our words.');
        // An edit that left the hash as the origin's (the edit is what diverges), so guards 1 and 2 hold and 3 must refuse.
        $this->assertSame($old, $this->guide()->asserted_hash);
        $before = $this->snapshot();

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('an authored change', $output);
        $this->assertStringContainsString('0 re-stamped of 0', $output);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame("Our words.\n", app(Reasserter::class)->currentBody($this->guide()), 'the host edit is kept');
        $this->assertTrue($this->diverged($this->guide()), 'and docs.diverged still names it');
    }

    public function test_restamp_refuses_a_row_with_a_draft_pending(): void
    {
        $this->bornUnstamped();
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        (require $core.'/database/migrations/shared/create_activity_log_table.php.stub')->up();
        // A draft whose words are the file's own (fresh node ids, same print): guards 1–3 hold, so only the draft refuses it.
        $pub = app(EntryPublication::class);
        $entry = $this->guide();
        $pub->recordDraft($entry, app(\Splicewire\Beam\Ux\JsonDoc\SourceToBlocks::class)->convert("A paragraph the author\nhard-wrapped at a narrow width.\n", [], null, $entry->format));
        $this->assertTrue($pub->state($this->guide())->draftPending);
        $before = $this->snapshot();

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('draft pending', $output);
        $this->assertStringContainsString('0 re-stamped of 0', $output);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_restamp_over_the_declared_sources_reaches_package_sources_and_names_an_orphan(): void
    {
        $this->bornUnstamped('acme/docs');
        config(['beam.docs.sources' => [['path' => $this->root, 'package' => 'acme/docs']]]);
        // A seeded row whose file is gone (www's `engines`): listed with its reason, never written.
        $orphan = BeamUxEntry::query()->create(['slug' => 'engines', 'type' => UxType::Page, 'format' => 'mdx', 'namespace' => null]);
        \DB::table('beam_ux_entries')->where('id', $orphan->id)->update(['origin' => 'disk:engines.mdx', 'asserted_hash' => str_repeat('0', 64)]);

        $output = $this->restamp(options: ['--restamp' => true, '--sources' => true]);
        $this->assertStringContainsString('engines (disk:engines.mdx)', $output);
        $this->assertStringContainsString('no origin file under the declared sources', $output);
        $this->assertStringContainsString('guide (package:acme/docs)', $output);
        $this->assertStringContainsString('1 would re-stamp · 1 left alone (0 pristine). Nothing was written', $output);
        $output = $this->restamp(options: ['--restamp' => true, '--sources' => true, '--apply' => true]);
        $this->assertStringContainsString('1 re-stamped of 1 that qualified · 1 left alone', $output);
        $this->assertTrue(app(Reasserter::class)->isPristine($this->guide()));
        $this->assertSame(str_repeat('0', 64), $orphan->fresh()->asserted_hash);
    }

    // ── An unreadable body, and a print change (registry-docs ticket 16) ───────────────────────────────────────────────

    /** The flagship's diagnostic, verbatim in shape (registry-docs ticket 14 §1). */
    private const UNRESOLVED = 'beam-mdx lens: cannot resolve [@splicewire/beam-mdx/lens] from the host at /srv/host ([@splicewire/beam-mdx] does not export [./lens])';

    /** Swap the host's MDX lens; every print and read after this goes through it. */
    private function useLens(\Splicewire\Beam\Mdx\Lens\MdxLens $lens): void
    {
        $this->app->instance(\Splicewire\Beam\Mdx\Lens\MdxLens::class, $lens);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\JsonDoc\SourceToBlocks::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
    }

    /** A row registered correctly (born pristine) on a host whose lens worked. */
    private function registeredPristine(string $slug = 'guide', string $source = self::WRAPPED): BeamUxEntry
    {
        if (! Schema::hasColumn('beam_ux_entries', 'seo_description')) {
            (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        }
        $this->holdReflowingBlocks();
        file_put_contents($this->root."/page/{$slug}.mdx", $source);
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $entry = BeamUxEntry::query()->where('slug', $slug)->firstOrFail();
        $this->assertTrue(app(Reasserter::class)->isPristine($entry), "{$slug} is born pristine");

        return $entry;
    }

    public function test_restamp_names_a_row_this_host_cannot_print_as_unreadable_never_as_an_edit(): void
    {
        $this->registeredPristine();
        $this->useLens(new UnresolvableMdxLens(self::UNRESOLVED));
        $before = $this->snapshot();

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('guide (package:acme/docs)', $output);
        $this->assertStringContainsString('stored body unreadable on this host ('.self::UNRESOLVED.')', $output);
        $this->assertStringNotContainsString('asserted hash is not this file', $output);
        $this->assertStringContainsString('0 re-stamped of 0 that qualified · 1 left alone (0 pristine) · 1 stored body unreadable on this host', $output);
        $this->assertSame($before, $this->snapshot(), 'an unreadable row is never written');

        // The whole-host mode: a row no declared source reaches is unreadable too, not "no origin file".
        mkdir($this->root.'/elsewhere');
        config(['beam.docs.sources' => [['path' => $this->root.'/elsewhere']]]);
        $output = $this->restamp(options: ['--restamp' => true, '--sources' => true]);
        $this->assertStringContainsString('stored body unreadable on this host ('.self::UNRESOLVED.')', $output);
        $this->assertStringNotContainsString('no origin file under the declared sources', $output);
        $this->assertStringContainsString('1 stored body unreadable on this host', $output);
    }

    public function test_a_rescan_reports_a_row_it_could_not_read_apart_from_one_it_kept_as_edited(): void
    {
        $this->registeredPristine();
        $this->registeredPristine('notes', "---\ntitle: Notes\n---\n\nNotes the file\nwraps.\n");
        $this->authorEditOf(BeamUxEntry::query()->where('slug', 'notes')->firstOrFail(), 'Our words.');
        $this->useLens(new OnlyNotesMdxLens(new ReflowingMdxLens, self::UNRESOLVED));
        \Illuminate\Support\Facades\Log::spy();

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $this->assertSame(['page/guide.mdx' => self::UNRESOLVED], $result['unreadable'] ?? null, 'unreadable, with the lens diagnostic');
        $this->assertSame(['page/notes.mdx'], $result['skipped'], 'the edited row is kept, and only it is "skipped"');
        $this->assertSame([], $result['reasserted']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'stored body unreadable on this host') && str_contains($message, self::UNRESOLVED));

        $exit = \Illuminate\Support\Facades\Artisan::call('splicewire:beam:ux:register-from-disk', ['path' => $this->root]);
        $output = (string) preg_replace('/\s+/', ' ', \Illuminate\Support\Facades\Artisan::output());
        $this->assertStringContainsString('page/guide.mdx', $output);
        $this->assertStringContainsString('not re-asserted: stored body unreadable on this host ('.self::UNRESOLVED.')', $output);
        $this->assertStringContainsString('· 1 not re-asserted (stored body unreadable on this host)', $output);
        $this->assertSame(0, $exit, $output);
    }

    public function test_after_a_print_change_restamp_restamps_an_untouched_row_and_refuses_a_host_edit(): void
    {
        $this->registeredPristine();
        $notes = $this->registeredPristine('notes', "---\ntitle: Notes\n---\n\nNotes the file\nwraps.\n");
        $this->authorEditOf($notes, 'Our words.');
        $editedHash = $notes->fresh()->asserted_hash;

        // The deliberate print change: the lens now ends every document with a blank line. Every stored print re-hashes.
        $this->useLens(new ChangedPrintMdxLens(new ReflowingMdxLens));
        $this->assertFalse(app(Reasserter::class)->isPristine($this->guide()), 'a print change leaves the untouched row non-pristine');
        $before = $this->snapshot();

        $output = $this->restamp();
        $this->assertMatchesRegularExpression('/guide \(package:acme\/docs\) (\.+ )?would re-stamp/', $output);
        $this->assertMatchesRegularExpression('/notes \(package:acme\/docs\) (\.+ )?asserted hash is not this file/', $output);
        $this->assertStringContainsString('1 would re-stamp · 1 left alone (0 pristine). Nothing was written', $output);
        $this->assertSame($before, $this->snapshot());

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString('1 re-stamped of 1 that qualified · 1 left alone (0 pristine)', $output);
        $this->assertTrue(app(Reasserter::class)->isPristine($this->guide()), 'the untouched row is pristine under the new print');
        $notes = $notes->fresh();
        $this->assertFalse(app(Reasserter::class)->isPristine($notes), 'the host edit stays edited');
        $this->assertSame($editedHash, $notes->asserted_hash);
        $this->assertSame("Our words.\n\n", app(Reasserter::class)->currentBody($notes), 'and its words are kept');

        // And the repaired row follows its file again.
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: A guide\n---\n\nRevised on disk.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $this->assertSame(['page/guide.mdx'], $result['reasserted']);
    }

    /**
     * Ticket 15's corpus (`splicewire/laravel-beam-mdx` `tests/Lens/fixtures/print-stability/`, 28 real docs pages) through
     * a deliberate print change. With `BEAM_MDX_LENS_HOST` the lens is the real one under the change; without, the
     * reflowing fake reads the same files.
     */
    public function test_after_a_print_change_restamp_restamps_every_untouched_corpus_page_and_refuses_the_edited_one(): void
    {
        $dir = dirname((string) (new \ReflectionClass(\Splicewire\Beam\Mdx\Lens\NodeMdxLens::class))->getFileName(), 3).'/tests/Lens/fixtures/print-stability';
        $this->assertFileExists($dir.'/corpus.json', 'ticket 15\'s corpus is reachable from this vendor');
        $slugs = array_keys((array) json_decode((string) file_get_contents($dir.'/corpus.json'), true));
        $this->assertGreaterThanOrEqual(25, count($slugs), 'an emptied corpus cannot pass');

        $host = getenv('BEAM_MDX_LENS_HOST') ?: null;
        $lens = $host !== null ? new \Splicewire\Beam\Mdx\Lens\NodeMdxLens('node', null, $host) : new ReflowingMdxLens;
        if (! Schema::hasColumn('beam_ux_entries', 'seo_description')) {
            (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        }
        $this->holdReflowingBlocks();
        $this->useLens($lens);
        @unlink($this->root.'/page/guide.mdx');
        $names = [];
        foreach ($slugs as $slug) {
            $name = 'corpus-'.str_replace(['.', '_'], '-', $slug);
            copy("{$dir}/{$slug}.source.mdx", $this->root."/page/{$name}.mdx");
            $names[] = $name;
        }
        app(RegisterEntriesFromDisk::class)->scan($this->root, package: 'acme/docs');
        $rows = BeamUxEntry::query()->whereIn('slug', $names)->get();
        $this->assertCount(count($names), $rows, 'every corpus page registered');
        foreach ($rows as $row) {
            $this->assertTrue(app(Reasserter::class)->isPristine($row), "{$row->slug} is born pristine");
        }
        $edited = $rows->firstWhere('slug', 'corpus-music-loop');
        $this->authorEditOf($edited, 'Our words.');

        $this->useLens(new ChangedPrintMdxLens($lens));
        $untouched = count($names) - 1;
        $output = $this->restamp();
        $this->assertStringContainsString("{$untouched} would re-stamp · 1 left alone (0 pristine). Nothing was written", $output);
        $this->assertMatchesRegularExpression('/corpus-music-loop \(package:acme\/docs\) (\.+ )?asserted hash is not this file/', $output);

        $output = $this->restamp(apply: true);
        $this->assertStringContainsString("{$untouched} re-stamped of {$untouched} that qualified · 1 left alone (0 pristine)", $output);
        foreach (BeamUxEntry::query()->whereIn('slug', $names)->get() as $row) {
            $this->assertSame($row->slug !== 'corpus-music-loop', app(Reasserter::class)->isPristine($row), $row->slug);
        }
    }

    public function test_restamp_refuses_options_out_of_place(): void
    {
        $this->artisan('splicewire:beam:ux:register-from-disk', ['path' => $this->root, '--apply' => true])->assertFailed();
        $this->artisan('splicewire:beam:ux:register-from-disk', ['path' => $this->root, '--restamp' => true, '--sources' => true])->assertFailed();
        $this->artisan('splicewire:beam:ux:register-from-disk', ['--restamp' => true])->assertFailed();
    }

    /** `docs.diverged`'s own comparison (laravel-beam-docs `DocsDivergedAudit`): the stored body's hash against the asserted one. */
    private function diverged(BeamUxEntry $entry): bool
    {
        $stored = app(StorageDriverResolver::class)->resolve($entry)->read((string) $entry->particle_id)->body;

        return \Splicewire\Beam\Ux\Provenance\Provenance::hash($entry->title, $entry->codec()->decode($stored)) !== (string) $entry->asserted_hash;
    }

    /** A site edit, published as an author's write (which records activity). */
    private function authorEdit(string $text): void
    {
        $this->authorEditOf($this->guide(), $text);
    }

    /** {@see authorEdit()} on any row. */
    private function authorEditOf(BeamUxEntry $entry, string $text): void
    {
        $core = dirname((new \ReflectionClass(\Splicewire\Beam\BeamServiceProvider::class))->getFileName(), 2);
        if (! Schema::hasTable('activity_log')) {
            (require $core.'/database/migrations/shared/create_activity_log_table.php.stub')->up();
        }
        $pub = app(EntryPublication::class);
        $edited = [['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]], 'dynamic' => false]];
        $pub->publishVersion($entry->fresh(), $pub->recordDraft($entry->fresh(), $edited)->headVersion);
    }

    /**
     * Hold MDX as blocks with a fake lens that does what the real one does to these files: one block per paragraph, a
     * hard-wrapped paragraph printed back on one line. So the stored print is NOT the file, as on www.
     */
    private function holdReflowingBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
        $this->app->instance(\Splicewire\Beam\Mdx\Lens\MdxLens::class, new ReflowingMdxLens);
    }

    /** The host turns `holds_blocks` on: the codecs are chosen afresh, and a lens reads `## From MDX` as one heading. */
    private function holdBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
        $this->app->instance(\Splicewire\Beam\Mdx\Lens\MdxLens::class, new class implements \Splicewire\Beam\Mdx\Lens\MdxLens
        {
            public function read(string $body, array $context = []): array
            {
                return ['doc' => [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From MDX']], 'dynamic' => false]], 'meta' => null];
            }

            public function print(array $doc): string
            {
                return "## From MDX\n";
            }
        });
    }

    private function publishedBody(BeamUxEntry $entry): array
    {
        return (array) app(VersionStore::class)->version($entry->particle, $entry->published_version)->snapshot['payload'];
    }

    public function test_a_blocks_mdx_page_takes_its_seo_fields_from_frontmatter_and_refuses_an_invalid_one(): void
    {
        // ADR-0233 d3: on a host that holds MDX as blocks, frontmatter is the page's settings, SEO fields included.
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $page = "---\ntitle: Handbook\ndescription: How the handbook works\npageType: Article\npublished_date: 2026-10-01\n---\n\n## From MDX\n";
        $invalid = "---\ntitle: Notes\npage_type: Blog\n---\n\n## From MDX\n";
        file_put_contents($this->root.'/page/legacy.mdx', $page);
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $this->assertNull(BeamUxEntry::query()->where('slug', 'legacy')->firstOrFail()->seo_description, 'a host that keeps MDX as source is unchanged');
        @unlink($this->root.'/page/legacy.mdx');

        $this->holdMdxAsBlocks();
        file_put_contents($this->root.'/page/handbook.mdx', $page);
        file_put_contents($this->root.'/page/notes.mdx', $invalid);
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $handbook = BeamUxEntry::query()->where('slug', 'handbook')->firstOrFail();
        $this->assertSame('How the handbook works', $handbook->seo_description);
        $this->assertSame('Article', $handbook->seo_page_type);
        $this->assertSame('2026-10-01', $handbook->seo_published_date);
        $notes = BeamUxEntry::query()->where('slug', 'notes')->firstOrFail();
        $this->assertNull($notes->seo_page_type);
        $this->assertStringContainsString('page_type', $result['failed']['page/notes.mdx'] ?? '');

        @unlink($this->root.'/page/handbook.mdx');
        @unlink($this->root.'/page/notes.mdx');
    }

    public function test_a_blocks_mdx_page_takes_its_description_from_excerpt_when_it_declares_none(): void
    {
        // This estate authors a page's summary as `excerpt` (WWW 28 files, the flagship 32, `description` in none), so
        // with `description` alone no page had a description, in its head or its Structured Data.
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $this->holdMdxAsBlocks();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: What the guide covers\n---\n\n## From MDX\n");
        file_put_contents($this->root.'/page/both.mdx', "---\ntitle: Both\nexcerpt: The summary\ndescription: The description\n---\n\n## From MDX\n");

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertSame('What the guide covers', BeamUxEntry::query()->where('slug', 'guide')->firstOrFail()->seo_description);
        $this->assertSame('The description', BeamUxEntry::query()->where('slug', 'both')->firstOrFail()->seo_description, 'a declared description wins');

        @unlink($this->root.'/page/guide.mdx');
        @unlink($this->root.'/page/both.mdx');
    }

    public function test_a_rescan_fills_an_existing_pages_empty_settings_from_its_file_and_overwrites_none(): void
    {
        // A page registered before its file's settings were read has none. The next scan (a host's seed) fills the
        // columns that are still empty, from the file, and never one that holds a value: an author's own stays.
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $this->holdMdxAsBlocks();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: What the guide covers\npage_type: Article\n---\n\n## From MDX\n");
        file_put_contents($this->root.'/page/kept.mdx', "---\ntitle: Kept\nexcerpt: From the file\n---\n\n## From MDX\n");
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        BeamUxEntry::query()->where('slug', 'guide')->update(['seo_description' => null, 'seo_page_type' => null]);
        BeamUxEntry::query()->where('slug', 'kept')->update(['seo_description' => 'The author\'s own']);

        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $guide = BeamUxEntry::query()->where('slug', 'guide')->firstOrFail();
        $this->assertSame('What the guide covers', $guide->seo_description);
        $this->assertSame('Article', $guide->seo_page_type);
        $this->assertSame('The author\'s own', BeamUxEntry::query()->where('slug', 'kept')->firstOrFail()->seo_description);

        @unlink($this->root.'/page/guide.mdx');
        @unlink($this->root.'/page/kept.mdx');
    }

    public function test_a_dry_run_names_the_empty_settings_a_rescan_would_fill_and_the_rescan_reports_them(): void
    {
        // A host fills its pages' settings in a GO, dry run first, with counts (orch-lead 22:06Z): the plan names each
        // page and the settings it would gain, writes nothing, and the scan reports what it filled.
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $this->holdMdxAsBlocks();
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\nexcerpt: What the guide covers\npage_type: Article\n---\n\n## From MDX\n");
        file_put_contents($this->root.'/page/kept.mdx', "---\ntitle: Kept\nexcerpt: From the file\n---\n\n## From MDX\n");
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        BeamUxEntry::query()->where('slug', 'guide')->update(['seo_description' => null, 'seo_page_type' => null]);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);

        $fills = $this->app->make(RegisterEntriesFromDisk::class)->plannedFills($this->root);

        $this->assertSame(['page/guide.mdx' => ['seo_description', 'seo_page_type']], $fills);
        $this->assertNull(BeamUxEntry::query()->where('slug', 'guide')->firstOrFail()->seo_description, 'a plan writes nothing');
        $this->artisan('splicewire:beam:ux:register-from-disk', ['path' => $this->root, '--dry-run' => true])
            ->expectsOutputToContain('would fill: description, page_type')
            ->expectsOutputToContain('1 would fill page settings')
            ->assertSuccessful();

        $this->artisan('splicewire:beam:ux:register-from-disk', ['path' => $this->root])
            ->expectsOutputToContain('1 filled page settings')
            ->assertSuccessful();
        $this->assertSame('What the guide covers', BeamUxEntry::query()->where('slug', 'guide')->firstOrFail()->seo_description);

        @unlink($this->root.'/page/guide.mdx');
        @unlink($this->root.'/page/kept.mdx');
    }

    public function test_a_blocks_mdx_page_keeps_the_frontmatter_keys_nothing_declares_in_its_registered_version(): void
    {
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        $this->holdMdxAsBlocks();
        file_put_contents($this->root.'/page/recipe.mdx', "---\ntitle: Recipe\nnavOrder: 4\nschemaType: HowTo\naudience: cooks\n---\n\n## From MDX\n");
        file_put_contents($this->root.'/page/untitled.mdx', "---\naudience: cooks\n---\n\n## From MDX\n");

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $recipe = BeamUxEntry::query()->where('slug', 'recipe')->firstOrFail();
        $version = app(VersionStore::class)->version($recipe->versionable(), $recipe->published_version)->snapshot;
        $this->assertSame(['schema_type' => 'HowTo', 'audience' => 'cooks'], $version['meta']['pageSettings']['unclaimed']);
        $this->assertSame('Recipe', $version['meta']['pageSettings']['title']);
        $this->assertSame(4, $version['meta']['pageSettings']['navOrder']);
        $this->assertStringContainsString('were not kept', $result['failed']['page/untitled.mdx'] ?? '', 'a page with no title cannot hold settings');

        @unlink($this->root.'/page/recipe.mdx');
        @unlink($this->root.'/page/untitled.mdx');
    }

    public function test_a_parent_file_declares_page_fields_and_its_childs_frontmatter_holds_them_typed(): void
    {
        // ADR-0233 d4: the parent's `childFields` names the host's class; the child's keys it declares are Page Fields.
        (require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub')->up();
        (require dirname(__DIR__).'/database/migrations/shared/add_child_fields_to_beam_ux_entries_table.php.stub')->up();
        config(['beam.ux.page_fields' => ['recipe' => Fixtures\RecipeFields::class]]);
        $this->holdMdxAsBlocks();
        mkdir($this->root.'/recipes/page', 0777, true);
        file_put_contents($this->root.'/page/recipes.mdx', "---\ntitle: Recipes\nchildFields: recipe\n---\n\n## From MDX\n");
        file_put_contents($this->root.'/recipes/page/pie.mdx', "---\ntitle: Pie\nservings: 4\nvegetarian: true\nschema_type: Recipe\n---\n\n## From MDX\n");

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $parent = BeamUxEntry::query()->where('slug', 'recipes')->firstOrFail();
        $this->assertSame('recipe', $parent->child_fields);
        $pie = BeamUxEntry::query()->where('slug', 'pie')->firstOrFail();
        $this->assertSame($parent->id, $pie->parent_id);
        $version = app(VersionStore::class)->version($pie->versionable(), $pie->published_version)->snapshot;
        $this->assertSame(['servings' => 4, 'vegetarian' => true], $version['meta']['pageSettings']['fields']);
        $this->assertSame(['schema_type' => 'Recipe'], $version['meta']['pageSettings']['unclaimed']);
        $this->assertArrayNotHasKey('recipes/page/pie.mdx', $result['failed']);

        @unlink($this->root.'/recipes/page/pie.mdx');
        @rmdir($this->root.'/recipes/page');
        @rmdir($this->root.'/recipes');
        @unlink($this->root.'/page/recipes.mdx');
    }

    /** Hold MDX as blocks with a fake MDX Lens: the Node run is proven in laravel-beam-mdx. */
    private function holdMdxAsBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
        $this->app->instance(\Splicewire\Beam\Mdx\Lens\MdxLens::class, new class implements \Splicewire\Beam\Mdx\Lens\MdxLens
        {
            public function read(string $body, array $context = []): array
            {
                return ['doc' => [['kind' => 'block', 'name' => 'h2', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From MDX']], 'dynamic' => false]], 'meta' => null];
            }

            public function print(array $doc): string
            {
                return "## From MDX\n";
            }
        });
    }

    /** A fake lens (the Node run is proven end-to-end elsewhere) and a mirror disk rooted at the scanned tree. */
    private function mirrorOverScannedTree(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $this->app->bind(TsxLens::class, fn () => new class implements TsxLens
        {
            public function lower(string $source): array
            {
                return [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'From disk']], 'dynamic' => false]];
            }
        });
        $disk = Storage::fake('mirror-source');
        $this->app->instance(PlacedDiskMirror::class, new PlacedDiskMirror($disk));
        $this->app->forgetInstance(EntryPublication::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);

        return $disk;
    }

    public function test_registering_a_tsx_page_never_rewrites_the_file_it_read(): void
    {
        // Lowering is not byte-preserving (ADR-0228 d9): mirroring the registered body back rewrote the authored file into
        // canonical TSX, so a dev seed dirtied tracked sources (launch row qitem-20261020153346-104d5668).
        $disk = $this->mirrorOverScannedTree();
        $authored = "// Authored.\nexport default function Home() {\n    return <section>From disk</section>;\n}\n";
        $disk->put('page/home.tsx', $authored);
        touch($disk->path('page/home.tsx'), $before = time() - 3600);
        clearstatcache();

        app(RegisterEntriesFromDisk::class)->scan($disk->path(''));

        $entry = BeamUxEntry::query()->where('slug', 'home')->whereNull('namespace')->firstOrFail();
        $this->assertNotNull($entry->published_version, 'the entry was not born published');
        $this->assertSame('page/home.tsx', app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry)->pathFor($entry),
            'the mirror target is not the file that was read, so this test proves nothing');
        clearstatcache();
        $this->assertSame($authored, $disk->get('page/home.tsx'));
        $this->assertSame($before, filemtime($disk->path('page/home.tsx')));
    }

    public function test_hydrating_an_empty_entry_from_its_file_never_rewrites_that_file(): void
    {
        $disk = $this->mirrorOverScannedTree();
        $authored = "// Authored.\nexport default function Home() {\n    return <section>From disk</section>;\n}\n";
        $disk->put('page/home.tsx', $authored);
        touch($disk->path('page/home.tsx'), $before = time() - 3600);
        clearstatcache();
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'title' => 'Home']);

        $this->assertTrue(app(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, $authored));

        $this->assertNotNull($entry->fresh()->published_version);
        clearstatcache();
        $this->assertSame($authored, $disk->get('page/home.tsx'));
        $this->assertSame($before, filemtime($disk->path('page/home.tsx')));
    }

    public function test_an_editor_publish_still_writes_its_placement_file(): void
    {
        // The other half of the rule: only a body read from its own file skips the mirror; a save is its own publish and
        // lands the git-trackable file (ADR-0165).
        $disk = $this->mirrorOverScannedTree();
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'title' => 'Home']);
        $body = [['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Edited']], 'dynamic' => false]];
        app(EntryPublication::class)->write($entry, $body);

        app(EntryPublication::class)->publishWritten($entry->fresh());

        $path = app(\Splicewire\Beam\Ux\Placement\PlacementResolver::class)->resolve($entry->fresh())->pathFor($entry->fresh());
        $this->assertTrue($disk->exists($path), 'an editor publish no longer writes its file');
        $this->assertStringContainsString('Edited', $disk->get($path));
    }

    public function test_a_registered_entry_is_born_published_with_a_pin_and_a_compiled_artifact(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->published_version, 'a disk-registered entry was left without a Publication Pin');
        $this->assertFalse(app(EntryPublication::class)->state($entry)->draftPending);
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertStringContainsString('Registered from disk.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_a_rescan_is_idempotent_the_pin_and_the_history_do_not_move(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $pin = (string) $this->guide()->published_version;

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertSame($pin, (string) $this->guide()->published_version);
        $this->assertSame(1, $this->versionRows($this->guide()));
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'guide')->count());
    }

    public function test_a_failure_while_pinning_surfaces_leaves_no_row_and_a_rescan_registers_it(): void
    {
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('a failure while pinning was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($this->guide(), 'a failed publication left a pin-less row');
        $this->assertSame(0, (int) \DB::table(Beam::table('versions'))->count(), 'a failed publication left a snapshot');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNotNull($this->guide()->published_version, 'a rescan could not register the entry');
    }

    public function test_a_rescan_repairs_a_pinless_registered_row_that_has_no_history(): void
    {
        // What register-from-disk left behind before entries were born published: a body, no pin, no history.
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nRegistered from disk."));
        $this->assertNull($entry->fresh()->published_version);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNotNull($entry->fresh()->published_version, 'a rescan left a pin-less row pin-less');
    }

    public function test_a_rescan_never_publishes_a_pinless_row_that_has_history_it_is_an_unpublished_draft(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        app(EntryPublication::class)->write($entry, $entry->codec()->encode("# Guide\n\nA draft."));
        app(VersionStore::class)->snapshot(BeamParticle::find($entry->fresh()->particle_id), 'draft');

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertNull($entry->fresh()->published_version, 'a rescan published an unpublished draft');
    }

    public function test_registering_without_the_version_store_refuses_before_writing_anything(): void
    {
        Schema::drop(Beam::table('versions'));

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('an entry was registered with no version store to pin it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vendor:publish --tag=beam-migrations --tag=beam-ux-migrations', $e->getMessage());
        }

        $this->assertNull($this->guide(), 'a refused registration left a row behind');
    }

    public function test_a_changed_source_on_rescan_publishes_a_new_version_and_the_artifact_follows_the_pin(): void
    {
        // review-r1 (2026-10-16): a pristine row whose disk source changed is re-asserted. That rewrote the body and
        // force-compiled it under the OLD pin, so the served artifact no longer matched the published version.
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $first = (string) $this->guide()->published_version;

        $this->writeGuide("# Guide\n\nChanged on disk.\n");
        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertContains('page/guide.mdx', $result['reasserted']);
        $this->assertNotSame($first, (string) $entry->published_version, 'a changed source left the pin on the old version');
        $this->assertSame(2, $this->versionRows($entry));
        $this->assertFalse(app(EntryPublication::class)->state($entry)->draftPending);
        $this->assertStringContainsString('Changed on disk.', (string) app(EntryArtifactStore::class)->read($entry));
    }

    public function test_a_changed_source_without_the_version_store_refuses_before_touching_the_row(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $before = $this->guide();
        $body = app(StorageDriverResolver::class)->resolve($before)->read((string) $before->particle_id)?->body;
        Schema::drop(Beam::table('versions'));
        $this->writeGuide("# Guide\n\nChanged on disk.\n");

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
            $this->fail('a re-assert wrote with no version store to publish it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('vendor:publish --tag=beam-migrations --tag=beam-ux-migrations', $e->getMessage());
        }

        $after = $this->guide();
        $this->assertSame((string) $before->asserted_hash, (string) $after->asserted_hash, 'the provenance hash moved');
        $this->assertSame($body, app(StorageDriverResolver::class)->resolve($after)->read((string) $after->particle_id)?->body, 'the body was rewritten');
    }

    public function test_hydrating_an_empty_entry_is_born_published(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n"));

        $this->assertNotNull($entry->fresh()->published_version, 'a hydrated entry was left without a Publication Pin');
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertStringContainsString('Hydrated.', (string) app(EntryArtifactStore::class)->read($entry->fresh()));
    }

    public function test_a_failure_while_pinning_a_hydration_rolls_it_back_and_a_retry_hydrates(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->failNextPinSave();

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n");
            $this->fail('a failure while pinning a hydration was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($entry->fresh()->particle_id, 'a failed hydration left a body behind');
        $this->assertSame(0, (int) \DB::table(Beam::table('versions'))->count());

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry->fresh(), "# Guide\n\nHydrated.\n"));
        $this->assertNotNull($entry->fresh()->published_version);
    }

    public function test_hydrating_an_entry_that_already_has_a_body_changes_nothing(): void
    {
        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);
        $entry = $this->guide();
        $pin = (string) $entry->published_version;

        $this->assertFalse($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Other\n"));

        $this->assertSame($pin, (string) $entry->fresh()->published_version);
        $this->assertSame(1, $this->versionRows($entry));
    }

    public function test_a_compile_failure_on_registration_is_collected_while_the_entry_is_still_published(): void
    {
        $this->app->singleton(EntryBodyCompiler::class, fn () => new FailingDiskCompiler);
        $this->app->forgetInstance(CompileEntryBody::class);
        $this->app->forgetInstance(RegisterEntriesFromDisk::class);
        FailingDiskCompiler::$attempts = 0;

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $entry = $this->guide();
        $this->assertNotNull($entry->published_version, 'a compile failure unpinned the entry');
        $this->assertSame(1, $this->versionRows($entry));
        $this->assertSame(1, FailingDiskCompiler::$attempts, 'the entry was compiled more than once');
        $this->assertArrayHasKey('page/guide.mdx', $result['failed']);
        $this->assertStringContainsString('no toolchain here', $result['failed']['page/guide.mdx']);
    }

    public function test_a_central_entry_under_a_tenant_default_hydrates_on_central_and_a_failed_pin_rolls_its_body_back(): void
    {
        // review-r1 R2 (2026-10-16): EntryPromoter's shape, an entry on `central` while the default is a tenant's. The
        // hydration's transaction is on central; an unbound driver wrote the particle on the default, outside it.
        $this->withCentral();
        $entry = BeamUxEntry::on('central')->create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->failNextPinSave();

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nHydrated.\n");
            $this->fail('a failure while pinning a central hydration was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertNull($entry->fresh()->particle_id);
        $this->assertSame([0, 0], $this->rows('particles'), 'a failed central hydration left a particle (central, default)');
        $this->assertSame([0, 0], $this->rows('versions'));

        $this->assertTrue($this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry->fresh(), "# Guide\n\nHydrated.\n"));

        $this->assertNotNull($entry->fresh()->published_version, 'a central hydration was left unpinned');
        $this->assertSame([1, 0], $this->rows('particles'), 'the body left the entry connection');
        $this->assertSame([1, 0], $this->rows('versions'));
    }

    public function test_a_central_entry_under_a_tenant_default_reasserts_on_central_and_a_failed_pin_rolls_the_rewrite_back(): void
    {
        $this->withCentral();
        $entry = BeamUxEntry::on('central')->create(['slug' => 'guide', 'type' => 'page', 'format' => 'mdx', 'title' => 'Guide']);
        $this->app->make(RegisterEntriesFromDisk::class)->hydrateEmpty($entry, "# Guide\n\nRegistered from disk.\n");
        // Pristine, as a disk-registered row is: its provenance hash is taken over the stored body.
        $entry = $entry->fresh();
        $entry->forceFill(\Splicewire\Beam\Ux\Provenance\Provenance::stamp(\Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx'), 'Guide', "# Guide\n\nRegistered from disk.\n", $entry->codec()))->save();
        $pin = (string) $entry->fresh()->published_version;
        $body = $this->centralBody($entry);
        $this->failNextPinSave();

        try {
            app(Reasserter::class)->reassert($entry->fresh(), 'Guide', "# Guide\n\nChanged on disk.\n", \Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx'));
            $this->fail('a failure while pinning a central re-assert was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure saving the publication pin', $e->getMessage());
        }

        $this->assertSame($pin, (string) $entry->fresh()->published_version);
        $this->assertSame($body, $this->centralBody($entry), 'a failed central re-assert kept its rewrite');
        $this->assertSame([1, 0], $this->rows('particles'));
        $this->assertSame([1, 0], $this->rows('versions'));

        $this->assertTrue(app(Reasserter::class)->reassert($entry->fresh(), 'Guide', "# Guide\n\nChanged on disk.\n", \Splicewire\Beam\Ux\Provenance\Provenance::disk('page/guide.mdx')));

        $this->assertNotSame($pin, (string) $entry->fresh()->published_version, 'a central re-assert left the old pin');
        $this->assertSame([1, 0], $this->rows('particles'), 'the re-assert left the entry connection or its particle');
        $this->assertSame([2, 0], $this->rows('versions'));
    }

    /** A `central` connection carrying the same migrated schema, beside the (tenant) default. */
    private function withCentral(): void
    {
        config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $default = \DB::getDefaultConnection();
        \DB::setDefaultConnection('central');
        try {
            $this->createTables();
        } finally {
            \DB::setDefaultConnection($default);
        }
    }

    /** @return array{0: int, 1: int} [central, default] row counts of a beam table */
    private function rows(string $table): array
    {
        return [
            (int) \DB::connection('central')->table(Beam::table($table))->count(),
            (int) \DB::table(Beam::table($table))->count(),
        ];
    }

    private function centralBody(BeamUxEntry $entry): mixed
    {
        return \DB::connection('central')->table(Beam::table('particles'))->where('id', (string) $entry->fresh()->particle_id)->value('payload');
    }

    private function writeGuide(string $body): void
    {
        file_put_contents($this->root.'/page/guide.mdx', "---\ntitle: Guide\n---\n\n".$body);
    }

    private function failNextPinSave(): void
    {
        $failPin = true;
        BeamUxEntry::updating(function (BeamUxEntry $row) use (&$failPin) {
            if ($failPin && $row->isDirty('published_version')) {
                $failPin = false;

                throw new \RuntimeException('simulated failure saving the publication pin');
            }
        });
    }

    private function createTables(): void
    {
        // The shipped migrations, so the fixture is a migrated host (provenance included, which the re-assert path reads).
        $ux = dirname(__DIR__).'/database/migrations/shared/';
        $beam = dirname((new \ReflectionClass(BeamParticle::class))->getFileName(), 3).'/database/migrations/shared/';
        foreach (['create_beam_ux_entries_table', 'add_provenance_to_beam_ux_entries_table', 'add_published_version_to_beam_ux_entries_table'] as $stub) {
            (require $ux.$stub.'.php.stub')->up();
        }
        foreach (['create_beam_particles_table', 'create_beam_versions_table'] as $stub) {
            (require $beam.$stub.'.php.stub')->up();
        }
    }
}

/** A fake MDX lens shaped like the real one for prose: one `p` block per paragraph, hard wraps reflowed onto one line. */
class ReflowingMdxLens implements \Splicewire\Beam\Mdx\Lens\MdxLens
{
    public function read(string $body, array $context = []): array
    {
        $doc = [];
        foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }
            $doc[] = ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => (string) preg_replace('/\s*\n\s*/', ' ', trim($paragraph))]], 'dynamic' => false];
        }

        return ['doc' => $doc, 'meta' => null];
    }

    public function print(array $doc): string
    {
        return implode("\n\n", array_map(fn (array $block): string => (string) ($block['children'][0]['value'] ?? ''), $doc))."\n";
    }
}

/** A compiler with no toolchain: it echoes the source back as a module. */
class DiskFakeCompiler implements EntryBodyCompiler
{
    public function handles(BeamUxEntry $entry): bool
    {
        return in_array($entry->format?->value, ['mdx', 'tsx'], true);
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        return '/* compiled */ export default () => '.json_encode($source);
    }
}

/** A compiler that always fails, counting its attempts. */
class FailingDiskCompiler implements EntryBodyCompiler
{
    public static int $attempts = 0;

    public function handles(BeamUxEntry $entry): bool
    {
        return true;
    }

    public function compile(BeamUxEntry $entry, string $source): string
    {
        self::$attempts++;

        throw \Splicewire\Beam\Ux\Compile\CompilationFailed::for($entry, 'no toolchain here');
    }
}

/** A host whose `@splicewire/beam-mdx` cannot be resolved: every read and print throws the lens's own diagnostic. */
class UnresolvableMdxLens implements \Splicewire\Beam\Mdx\Lens\MdxLens
{
    public function __construct(private string $diagnostic) {}

    public function read(string $body, array $context = []): array
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['source' => $this->diagnostic]);
    }

    public function print(array $doc): string
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['source' => $this->diagnostic]);
    }
}

/** Prints only a doc whose words are an author's edit ("Our words."); any other print throws, as an unresolvable lens does. */
class OnlyNotesMdxLens implements \Splicewire\Beam\Mdx\Lens\MdxLens
{
    public function __construct(private \Splicewire\Beam\Mdx\Lens\MdxLens $inner, private string $diagnostic) {}

    public function read(string $body, array $context = []): array
    {
        return $this->inner->read($body, $context);
    }

    public function print(array $doc): string
    {
        if (($doc[0]['children'][0]['value'] ?? null) !== 'Our words.') {
            throw \Illuminate\Validation\ValidationException::withMessages(['source' => $this->diagnostic]);
        }

        return $this->inner->print($doc);
    }
}

/** A deliberate print change over any lens: the same reading, every document printed with one more trailing newline. */
class ChangedPrintMdxLens implements \Splicewire\Beam\Mdx\Lens\MdxLens
{
    public function __construct(private \Splicewire\Beam\Mdx\Lens\MdxLens $inner) {}

    public function read(string $body, array $context = []): array
    {
        return $this->inner->read($body, $context);
    }

    public function print(array $doc): string
    {
        return $this->inner->print($doc)."\n";
    }
}
