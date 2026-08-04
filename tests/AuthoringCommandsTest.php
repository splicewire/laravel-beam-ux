<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\Sitemap;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * F02 — the three PROMOTED, data-driven authoring commands: `ux-scaffold` (mint an empty entry),
 * `ux-seed-nav` (config/disk/derived nav — no hard-coded array), `ux-enrich-page-schemas` (generic
 * encode→stamp). Proves each reads DATA (config / disk-frontmatter) + honors the `beam.ux.namespace`
 * config default, never a hard-coded `audiostud` constant.
 */
class AuthoringCommandsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->root = sys_get_temp_dir().'/beamux-authoring-'.uniqid();
        mkdir($this->root, 0777, true);

        // A recording driver so the enrich write path needs no beam-core particle table.
        $this->app->extend(StorageDriverResolver::class, fn () => (new StorageDriverResolver)
            ->register(StorageDriverResolver::DEFAULT, new RecordingAuthoringDriver));

        // Point the mirror disk's root at the temp dir so enrich/nav-from-disk resolve there.
        Config::set('beam.ux.storage.mirror_disk', 'beam-ux-test');
        Config::set('filesystems.disks.beam-ux-test.root', $this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    public function test_scaffold_mints_an_empty_entry_under_the_config_namespace(): void
    {
        Config::set('beam.ux.namespace', 'demo');

        $this->artisan('splicewire:beam:ux-scaffold', ['slug' => 'section-template', '--type' => 'template'])
            ->assertSuccessful();

        $entry = BeamUxEntry::query()->where('namespace', 'demo')->where('slug', 'section-template')->first();
        $this->assertNotNull($entry);
        $this->assertSame(UxType::Template, $entry->type);

        // Idempotent — a re-run leaves the row untouched, no duplicate.
        $this->artisan('splicewire:beam:ux-scaffold', ['slug' => 'section-template', '--type' => 'template'])
            ->assertSuccessful();
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'section-template')->count());
    }

    public function test_scaffold_namespace_option_overrides_the_config_default(): void
    {
        Config::set('beam.ux.namespace', 'demo');

        $this->artisan('splicewire:beam:ux-scaffold', ['slug' => 'foo', '--namespace' => 'other', '--type' => 'page'])
            ->assertSuccessful();

        $this->assertNotNull(BeamUxEntry::query()->where('namespace', 'other')->where('slug', 'foo')->first());
        $this->assertNull(BeamUxEntry::query()->where('namespace', 'demo')->where('slug', 'foo')->first());
    }

    public function test_seed_nav_reads_a_config_override(): void
    {
        Config::set('beam.ux.namespace', 'demo');
        Config::set('beam.ux.nav', [
            'home' => ['segment' => '/', 'title' => 'Home', 'type' => 'page', 'realm' => 'site'],
            'lyrics' => ['segment' => '/account/lyrics', 'title' => 'Lyrics', 'type' => 'page', 'realm' => 'account'],
        ]);

        $this->artisan('splicewire:beam:ux-seed-nav')->assertSuccessful();

        $home = BeamUxEntry::query()->where('namespace', 'demo')->where('slug', 'home')->first();
        $this->assertNotNull($home);
        $this->assertSame('/', $home->segment);
        $this->assertSame('Home', $home->title);
        $this->assertSame('site', $home->realm);
        $this->assertGreaterThan(0, $home->nav_order);

        // A second realm gets its own sitemap.
        $lyrics = BeamUxEntry::query()->where('slug', 'lyrics')->first();
        $this->assertSame('account', $lyrics->realm);
        $this->assertNotSame($home->sitemap_id, $lyrics->sitemap_id);
    }

    public function test_seed_nav_demotes_behavior_realms_to_non_composable(): void
    {
        Config::set('beam.ux.namespace', 'demo');
        Config::set('beam-realms.behavior', ['auth']);
        Config::set('beam.ux.nav', [
            'home' => ['segment' => '/', 'title' => 'Home', 'realm' => 'site'],
            'login' => ['segment' => '/login', 'title' => 'Log in', 'realm' => 'auth'],
        ]);

        $this->artisan('splicewire:beam:ux-seed-nav')->assertSuccessful();

        $this->assertTrue((bool) BeamUxEntry::query()->where('slug', 'home')->first()->composable);
        $this->assertFalse((bool) BeamUxEntry::query()->where('slug', 'login')->first()->composable);
    }

    public function test_seed_nav_derives_from_entry_frontmatter_when_no_config_or_disk(): void
    {
        Config::set('beam.ux.namespace', 'demo');
        Config::set('beam.ux.nav', null);

        // Pre-register two entries carrying segment/realm/nav_order (as register-from-disk would from
        // frontmatter) — the derive path must project THESE into nav with no bespoke array.
        Sitemap::forRealm('site');
        BeamUxEntry::create(['slug' => 'studio', 'namespace' => 'demo', 'type' => 'page', 'realm' => 'site', 'segment' => '/studio', 'title' => 'Studio', 'nav_order' => 20]);
        BeamUxEntry::create(['slug' => 'home', 'namespace' => 'demo', 'type' => 'page', 'realm' => 'site', 'segment' => '/', 'title' => 'Home', 'nav_order' => 10]);
        // An entry with no segment is NOT nav (a template).
        BeamUxEntry::create(['slug' => 'tpl', 'namespace' => 'demo', 'type' => 'template', 'realm' => 'site']);

        $this->artisan('splicewire:beam:ux-seed-nav')->assertSuccessful();

        // The two segmented entries are restamped in nav_order; the template is not touched into nav.
        $home = BeamUxEntry::query()->where('slug', 'home')->first();
        $studio = BeamUxEntry::query()->where('slug', 'studio')->first();
        $this->assertSame('Home', $home->title);
        $this->assertLessThan($studio->nav_order, $home->nav_order);
    }

    public function test_enrich_page_schemas_encodes_frontmatter_and_stamps_schema(): void
    {
        Config::set('beam.ux.namespace', 'demo');

        // An .mdx page on disk at the placement path {namespace}/page/{slug}.mdx.
        @mkdir($this->root.'/demo/page', 0777, true);
        file_put_contents($this->root.'/demo/page/about.mdx', <<<'MDX'
        ---
        heading: About
        intent: Who we are
        ---
        We build **songs**.
        MDX);

        BeamUxEntry::create(['slug' => 'about', 'namespace' => 'demo', 'type' => 'page', 'format' => 'mdx', 'realm' => 'site']);

        $this->artisan('splicewire:beam:ux-enrich-page-schemas')->assertSuccessful();

        $entry = BeamUxEntry::query()->where('slug', 'about')->first();
        $this->assertNotNull($entry->schema_ref);
        $schema = json_decode($entry->schema_ref, true);
        $this->assertArrayHasKey('heading', $schema['properties']);

        $body = app(StorageDriverResolver::class)->resolve($entry)->read($entry->particle_id)?->body;
        $this->assertSame('About', $body['heading']);
        $this->assertStringContainsString('songs', $body['content']);
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
            $table->boolean('composable')->default(true);
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
            $table->integer('nav_order')->nullable();
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

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/** A recording {@see StorageDriver} — mints a stable fake key, records writes; no particle table needed. */
class RecordingAuthoringDriver implements StorageDriver
{
    /** @var array<string, array<string, mixed>> */
    public array $written = [];

    public function read(string $key): ?StorageItem
    {
        return isset($this->written[$key]) ? new StorageItem($key, $this->written[$key]) : null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'fake-'.count($this->written);
        $this->written[$key] = $body;

        return new StorageItem($key, $body);
    }

    public function list(?string $namespace = null): array
    {
        return [];
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return 0;
    }
}
