<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\UpdateFromNewer;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Nav\NavSource;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-build S8 (`beamux-build/issues/05`) — the two explicit-operator disk batches.
 *
 * `register-from-disk` scans a target for EVERY registered body format (format-aware, NOT tsx-only,
 * ADR-0164): a `.tsx` component and an `.mdx` page both register; `type` + `namespace` are inferred from
 * the path (reverse of S2 placement); the component gets a S9 DRAFT schema at import, the page does not;
 * a file already in the DB is skipped (idempotent). `update-from-newer` is config-gated and OFF by
 * default (a newer disk mtime does NOT flow back unless enabled), respects direction, and reconciles by
 * mtime when enabled. NO ambient watcher exists — proven by the class surface.
 *
 * The batches write through a REGISTERED StorageDriver; here the default driver is a recording fake (the
 * particle substrate is beam-core's own StorageDriverTest concern) so the test isolates BATCH behavior.
 */
class RegisterAndUpdateFromDiskTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->root = sys_get_temp_dir().'/beamux-disk-'.uniqid();
        mkdir($this->root, 0777, true);

        // Swap the default StorageDriver for a recording fake so the batch's write path needs no
        // beam-core particle table — the batch's OWN behavior is under test, not the driver's.
        $this->app->extend(StorageDriverResolver::class, function () {
            return (new StorageDriverResolver)
                ->register(StorageDriverResolver::DEFAULT, new RecordingDriver);
        });
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    public function test_register_from_disk_is_format_aware_infers_from_path_and_drafts_only_components(): void
    {
        // A `.tsx` component and an `.mdx` page — both register (format-aware, not tsx-only).
        $this->writeFile('kit/hero/component/hero.tsx', <<<'TSX'
        interface HeroProps { title: string; count?: number; }
        export const Hero = ({ title, count = 2 }: HeroProps) => <section>{title}</section>;
        TSX);
        $this->writeFile('site/pages/page/about.mdx', "# About\n\nHello.");

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['skipped']);

        // type + namespace inferred from the path (reverse of S2 DefaultPlacement).
        $hero = BeamUxEntry::where('slug', 'hero')->firstOrFail();
        $this->assertSame(UxType::Component, $hero->type);
        $this->assertSame('kit.hero', $hero->namespace);
        $this->assertSame('tsx', $hero->format->value);

        $about = BeamUxEntry::where('slug', 'about')->firstOrFail();
        $this->assertSame(UxType::Page, $about->type);
        $this->assertSame('site.pages', $about->namespace);
        $this->assertSame('mdx', $about->format->value);

        // S9 at import: the COMPONENT gets a DRAFT schema; the PAGE does not (excluded).
        $this->assertTrue($hero->schema_is_draft);
        $this->assertNotNull($hero->schema_ref);
        $this->assertArrayHasKey('title', json_decode($hero->schema_ref, true)['properties']);

        $this->assertFalse((bool) $about->schema_is_draft);
        $this->assertNull($about->schema_ref);
    }

    public function test_register_from_disk_persists_realm_segment_nav_order_title_from_frontmatter(): void
    {
        // A page whose OWN frontmatter declares its containment — the co-located registry, no nav.yml.
        $this->writeFile('site/pages/page/about.mdx', <<<'MDX'
        ---
        realm: account
        segment: /account/about
        nav_order: 20
        title: About Us
        ---
        # About

        Hello.
        MDX);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $about = BeamUxEntry::where('slug', 'about')->firstOrFail();
        $this->assertSame('account', $about->realm);
        $this->assertSame('/account/about', $about->segment);
        $this->assertSame(20, (int) $about->nav_order);
        $this->assertSame('About Us', $about->title);

        // The `realm` was set at CREATE time, so the model's creating hook defaulted `realms` to it.
        $this->assertSame(['account'], $about->realms);

        // …and it DERIVES into nav with no nav.yml / config override (the derive leg of the priority chain).
        config()->set('beam.ux.nav', null);
        $rows = $this->app->make(NavSource::class)->resolve('site.pages');
        $row = collect($rows)->firstWhere('slug', 'about');
        $this->assertNotNull($row, 'the page derives into nav from its own frontmatter');
        $this->assertSame('account', $row['realm']);
        $this->assertSame('/account/about', $row['segment']);
        $this->assertSame(20, $row['nav_order']);
    }

    public function test_register_from_disk_falls_back_to_the_path_realm_convention_when_frontmatter_omits_realm(): void
    {
        // No `realm:` in frontmatter — the disk-path convention supplies it (frontmatter still owns segment).
        config()->set('beam.ux.realm_conventions', [
            '*/page/library-*' => 'account',
            '*/page/operator-*' => 'operator',
        ]);

        $this->writeFile('audiostud/page/library-lyrics.mdx', <<<'MDX'
        ---
        segment: /account/lyrics
        title: Lyrics
        ---
        The words a songwriter brought.
        MDX);
        $this->writeFile('audiostud/page/operator-dashboard.mdx', "---\nsegment: /operator\n---\nOps.");
        // A file matching NO convention keeps the model default `site`.
        $this->writeFile('audiostud/page/home.mdx', "---\nsegment: /\n---\nWelcome.");

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertSame('account', BeamUxEntry::where('slug', 'library-lyrics')->firstOrFail()->realm);
        $this->assertSame('operator', BeamUxEntry::where('slug', 'operator-dashboard')->firstOrFail()->realm);
        $this->assertSame('site', BeamUxEntry::where('slug', 'home')->firstOrFail()->realm);
    }

    public function test_register_from_disk_leaves_realm_default_and_no_nav_segment_when_nothing_declared(): void
    {
        // No frontmatter, no convention → model default `site`, null segment (⇒ NOT a nav row).
        $this->writeFile('site/pages/page/about.mdx', "# About\n\nHello.");

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $about = BeamUxEntry::where('slug', 'about')->firstOrFail();
        $this->assertSame('site', $about->realm);
        $this->assertNull($about->segment);

        // With no segment it does not appear in the derived nav.
        config()->set('beam.ux.nav', null);
        $rows = $this->app->make(NavSource::class)->resolve('site.pages');
        $this->assertNull(collect($rows)->firstWhere('slug', 'about'));
    }

    public function test_register_from_disk_is_idempotent_skipping_files_already_in_the_db(): void
    {
        $this->writeFile('kit/hero/component/hero.tsx', 'export const Hero = () => null;');

        $batch = $this->app->make(RegisterEntriesFromDisk::class);

        $first = $batch->scan($this->root);
        $this->assertCount(1, $first['created']);

        // Re-run: the file already resolves to a record → skipped, none re-created.
        $second = $batch->scan($this->root);
        $this->assertCount(0, $second['created']);
        $this->assertContains('kit/hero/component/hero.tsx', $second['skipped']);
        $this->assertSame(1, BeamUxEntry::count());
    }

    public function test_register_from_disk_ignores_unrecognized_extensions(): void
    {
        $this->writeFile('kit/notes.txt', 'not a body');

        $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertCount(0, $result['created']);
        $this->assertContains('kit/notes.txt', $result['ignored']);
    }

    public function test_update_from_newer_is_off_by_default_a_newer_disk_file_does_not_flow_back(): void
    {
        config()->set('beam.ux.update_from_newer.enabled', false);

        $entry = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'namespace' => 'kit', 'particle_id' => 'p-1']);

        $batch = $this->app->make(UpdateFromNewer::class);
        $this->assertFalse($batch->enabled());

        // A disk file far newer than the record — but the gate is OFF, so nothing flows back.
        $path = $this->app->make(PlacementResolver::class)->resolve($entry)->pathFor($entry);
        $result = $batch->run([$path => 'new source'], [$path => time() + 9999]);

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['updated']);
    }

    public function test_update_from_newer_when_enabled_respects_direction_and_mtime(): void
    {
        config()->set('beam.ux.update_from_newer.enabled', true);
        config()->set('beam.ux.update_from_newer.direction', UpdateFromNewer::DIRECTION_DISK_TO_RECORD);

        $entry = BeamUxEntry::create(['slug' => 'hero', 'type' => UxType::Component, 'namespace' => 'kit', 'particle_id' => 'p-1']);
        $path = $this->app->make(PlacementResolver::class)->resolve($entry)->pathFor($entry);

        $batch = $this->app->make(UpdateFromNewer::class);
        $this->assertTrue($batch->enabled());

        // Disk NEWER than the record (RecordingDriver::staleness returns +1) → the disk body flows in.
        $newer = $batch->run([$path => 'newer disk source'], [$path => time() + 5000]);
        $this->assertCount(1, $newer['updated']);

        // Disk OLDER (RecordingDriver marked so) → nothing flows back.
        RecordingDriver::$diskIsNewer = false;
        $older = $batch->run([$path => 'older disk source'], [$path => 1]);
        $this->assertCount(0, $older['updated']);
        RecordingDriver::$diskIsNewer = true;
    }

    public function test_no_ambient_filesystem_watcher_exists_only_explicit_batches(): void
    {
        // The disk seam is exactly the two operator batches + the recognizer — no watcher/daemon class.
        $disk = glob(dirname(__DIR__).'/src/Disk/*.php');
        $names = array_map(fn ($p) => basename($p, '.php'), $disk);
        sort($names);

        $this->assertSame(
            ['RawMdxReader', 'RegisterEntriesFromDisk', 'RegisterFromDisk', 'UpdateFromNewer'],
            $names,
        );

        // No watcher/daemon MECHANISM anywhere in the disk seam (the prose may *say* "no watcher"; what
        // matters is no actual watch loop / daemon / filesystem-event hook is coded).
        foreach ($disk as $file) {
            $src = strtolower((string) file_get_contents($file));
            $this->assertStringNotContainsString('inotify', $src);
            $this->assertStringNotContainsString('watchevent', $src);
            $this->assertStringNotContainsString('->watch(', $src);
            $this->assertStringNotContainsString('while (true)', $src);
        }
    }

    /**
     * The directory chain carries CONTAINMENT, not just the disk-grouping namespace
     * (beam-docs-satellite ticket 08). Before this, an imported tree landed flat — every file an orphan
     * — and the containment tree that decides both the URL (ADR-0209 §3) and the nav (ADR-0165) had to
     * be rebuilt by hand after every import.
     */
    public function test_register_from_disk_derives_containment_from_the_directory_chain(): void
    {
        $root = BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE);

        $this->writeFile('guides/page/laravel.mdx', $this->page('Beam on Laravel', 'laravel'));
        $this->writeFile('guides/laravel/page/setup.mdx', $this->page('Set up', 'setup'));
        $this->writeFile('guides/laravel/setup/page/config.mdx', $this->page('Configure', 'config'));

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $laravel = BeamUxEntry::query()->where('slug', 'laravel')->firstOrFail();
        $setup = BeamUxEntry::query()->where('slug', 'setup')->firstOrFail();
        $config = BeamUxEntry::query()->where('slug', 'config')->firstOrFail();

        // A scan-root file hangs from the realm root; each nested file from the file named for its dir.
        $this->assertSame($root->getKey(), $laravel->parent_id);
        $this->assertSame($laravel->getKey(), $setup->parent_id);
        $this->assertSame($setup->getKey(), $config->parent_id);
    }

    /**
     * The parent must exist before the child names it, and the directory iterator's order is a
     * filesystem detail — so the batch sorts by namespace depth.
     *
     * The walk order is forced DEEPEST-FIRST here rather than left to the iterator, and that is the whole
     * point of the test: written as three plain `writeFile` calls it passed with the sort deleted,
     * because this filesystem happened to yield a workable order. A guard that only fails on an unlucky
     * machine is the "regression test pinned to the same assumption as the code" shape — it has to force
     * the order the sort exists to survive.
     */
    public function test_containment_resolves_regardless_of_the_order_files_are_walked(): void
    {
        BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE);

        $this->writeFile('guides/laravel/setup/page/config.mdx', $this->page('Configure', 'config'));
        $this->writeFile('guides/laravel/page/setup.mdx', $this->page('Set up', 'setup'));
        $this->writeFile('guides/page/laravel.mdx', $this->page('Beam on Laravel', 'laravel'));

        $batch = new DeepestFirstRegisterBatch(
            $this->app->make(\Splicewire\Beam\Ux\Disk\RegisterFromDisk::class),
            $this->app->make(StorageDriverResolver::class),
            $this->app->make(\Splicewire\Beam\Ux\Inference\InferDraftSchema::class),
        );

        $batch->scan($this->root);

        $chain = $this->app->make(\Splicewire\Beam\Ux\Containment\EntryPathResolver::class)
            ->resolve('/laravel/setup/config');

        $this->assertNotNull($chain, 'the imported tree must resolve as a path, which is what containment buys');
        $this->assertSame('config', end($chain)->slug);
    }

    /**
     * `--under` is how a host imports a subtree BENEATH something that already exists — the seeded docs
     * root on `splicewire/www` being the case this was built for.
     */
    public function test_under_parents_scan_root_files_to_an_existing_entry(): void
    {
        $root = BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE);

        $docs = BeamUxEntry::create([
            'slug' => 'docs',
            'type' => UxType::Page,
            'parent_id' => $root->getKey(),
            'segment' => '/beam/docs',
            'title' => 'Documentation',
        ]);

        $this->writeFile('guides/page/laravel.mdx', $this->page('Beam on Laravel', 'laravel'));

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root, $docs);

        $this->assertSame(
            $docs->getKey(),
            BeamUxEntry::query()->where('slug', 'laravel')->firstOrFail()->parent_id,
        );
    }

    /**
     * A root is provisioned by install (ADR-0209 §9), never as a side effect of some other operation. A
     * host that has not installed imports flat — the pre-containment behaviour — rather than having a
     * realm root silently appear underneath it.
     */
    public function test_a_missing_realm_root_is_not_created_by_the_batch(): void
    {
        $this->writeFile('guides/page/laravel.mdx', $this->page('Beam on Laravel', 'laravel'));

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $this->assertSame(1, BeamUxEntry::count());
        $this->assertNull(BeamUxEntry::query()->where('slug', 'laravel')->firstOrFail()->parent_id);
    }

    private function page(string $title, string $segment): string
    {
        return "---\ntitle: {$title}\nsegment: {$segment}\n---\n\n# {$title}\n";
    }

    private function writeFile(string $relative, string $contents): void
    {
        $full = $this->root.'/'.$relative;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $contents);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * **The round trip is the invariant, and its absence is what let a live bug survive as long as it
     * did** (beam-docs-satellite ticket 06).
     *
     * The importer used to write `['source' => $raw]` for every format. That is exactly the shape
     * `TsxBodyCodec` round-trips, so the tsx cases passed and nothing else ever looked — but
     * `MdxBodyCodec` stores `{frontmatter, content}`, so an imported `.mdx` decoded to an **empty
     * string**, and `PlacedDiskMirror` (which decodes through the codec) would project a blank `.mdx`
     * straight back over the author's file with no error.
     *
     * A write path with no reader is unverified however green the suite is. So this guard is written
     * over **every** registered format rather than the two that happen to exist today: a third codec
     * whose body shape is not `{source}` fails here on the day it is registered, instead of on the day
     * someone finally reads one of its entries.
     */
    public function test_every_registered_format_round_trips_from_disk_through_the_body_and_back(): void
    {
        $sources = [
            'tsx' => "export default function Widget() { return <div>hi</div> }\n",
            'mdx' => "---\ntitle: Intro\nsegment: intro\n---\n\n# Intro\n\nBody copy.\n",
        ];

        $this->writeFile('kit/widget/component/widget.tsx', $sources['tsx']);
        $this->writeFile('guides/page/intro.mdx', $sources['mdx']);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($this->root);

        $seen = [];

        foreach (BeamUxEntry::query()->get() as $entry) {
            $format = (string) $entry->format?->value;
            $seen[] = $format;

            $body = $this->app->make(StorageDriverResolver::class)
                ->resolve($entry)
                ->read((string) $entry->particle_id)
                ?->body ?? [];

            $this->assertSame(
                $sources[$format],
                $entry->codec()->decode($body),
                "a [{$format}] body must decode back to the source it was imported from",
            );
        }

        // Both shipped body formats are genuinely exercised — a guard that silently covered only one
        // of them is how the original bug survived.
        sort($seen);
        $this->assertSame(['mdx', 'tsx'], $seen);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->nullable()->index();
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

/**
 * A recording {@see StorageDriver} standing in for the default Stacked(Particle, Disk) driver — it needs
 * no beam-core particle table, so the batch tests isolate BATCH behavior. `write('')` mints a stable
 * fake key; `staleness` reports disk newer/older per the static flag.
 */
/**
 * The batch, walking deepest-first — the adversarial order the depth sort exists to survive. Overriding
 * the walk is the only way to make the order deterministic; the real iterator's order is the filesystem's
 * business and differs by platform, which is exactly why the production code may not rely on it.
 */
class DeepestFirstRegisterBatch extends RegisterEntriesFromDisk
{
    protected function files(string $root): iterable
    {
        $files = iterator_to_array(parent::files($root), false);

        usort($files, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        return $files;
    }
}

class RecordingDriver implements StorageDriver
{
    public static bool $diskIsNewer = true;

    /** @var array<string, array<string, mixed>> */
    public array $written = [];

    public function read(string $key): ?StorageItem
    {
        return isset($this->written[$key]) ? new StorageItem($key, $this->written[$key]) : null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'p-'.(count($this->written) + 1);
        $this->written[$key] = $body;

        return new StorageItem($key, $body, $namespace, time());
    }

    public function list(?string $namespace = null): array
    {
        return [];
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return self::$diskIsNewer ? 1 : -1;
    }
}
