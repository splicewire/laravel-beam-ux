<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Codegen\PuckPageCodegen;
use Splicewire\Beam\Ux\Disk\PuckBridge;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\SyncPagesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beam Model-B ticket 08 — the **reverse file→DB sync leg** for Puck pages, end-to-end through the REAL
 * Node bridge.
 *
 * Proves: a seeded `page`'s Puck Data codegens (PHP) to a composed `.tsx`; an edit to that `.tsx` on
 * disk, synced back via {@see SyncPagesFromDisk} (which shells to the Node `beam-ux-puck-bridge` CLI),
 * updates the DB particle body to the edited Puck Data — last-writer by mtime. And that the round-trip
 * (Data → PHP codegen → Node parse → Data) is drift-free MODULO the structural `id` codegen strips.
 * Also that `register-from-disk` stores a page `.tsx` AS Puck Data, never as raw component source.
 *
 * Skipped when node or the built bridge dist is absent (degrade-not-fabricate — the bridge is optional).
 */
class SyncPagesFromDiskTest extends TestCase
{
    /** Absolute path to the Node bridge CLI in the sibling JS package (built dist required). */
    private const BRIDGE = __DIR__.'/../../../../../js/packages/beam/beam-ux/bin/puck-bridge.mjs';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        if (! is_file(self::BRIDGE) || ! is_file(dirname(self::BRIDGE).'/../dist/blockdoc.js')) {
            $this->markTestSkipped('The Node puck bridge dist is not built — run `npm run build` in @splicewire/beam-ux.');
        }

        // Point the container-bound PuckBridge at the real built CLI, and swap the default driver for a
        // recording fake so the batch's write path needs no beam-core particle table.
        $this->app->singleton(PuckBridge::class, fn () => new PuckBridge(script: realpath(self::BRIDGE), node: 'node'));
        $this->app->extend(StorageDriverResolver::class, fn () => (new StorageDriverResolver)
            ->register(StorageDriverResolver::DEFAULT, new RecordingSyncDriver));
    }

    protected function tearDown(): void
    {
        RecordingSyncDriver::$diskIsNewer = true;
        parent::tearDown();
    }

    public function test_a_page_tsx_edit_on_disk_syncs_into_the_db_puck_data_body(): void
    {
        // A seeded page whose body is a Puck Data document (Heading + Prose + ResourceList).
        $seed = [
            'root' => [],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'heading-seed', 'text' => 'Lyrics']],
                ['type' => 'ResourceList', 'props' => ['id' => 'rl-seed', 'resource' => 'library-lyrics']],
            ],
            'zones' => [],
        ];

        $entry = BeamUxEntry::create([
            'slug' => 'library-lyrics', 'type' => UxType::Page, 'namespace' => 'audiostud',
            'format' => 'tsx', 'particle_id' => 'p-1',
        ]);
        $driver = $this->app->make(StorageDriverResolver::class)->resolve($entry);
        $driver->write('p-1', $seed, 'audiostud');

        // DB → .tsx via the PHP codegen (the outbound leg that already exists).
        $codegen = new PuckPageCodegen('@/puck/blocks');
        $tsx = $codegen->generate($seed, 'library-lyrics');

        // A hand/agent EDIT to the composed .tsx on disk: change the Heading text.
        $edited = str_replace('text="Lyrics"', 'text="Lyrics (edited on disk)"', $tsx);
        $this->assertStringContainsString('Lyrics (edited on disk)', $edited);

        // Sync the disk edit back — disk is NEWER (RecordingSyncDriver::staleness → +1).
        $path = $this->app->make(PlacementResolver::class)->resolve($entry)->pathFor($entry);
        $result = $this->app->make(SyncPagesFromDisk::class)->run([$path => $edited], [$path => time() + 9999]);

        $this->assertCount(1, $result['updated']);

        // The DB particle body is now the EDITED Puck Data (parsed back by the Node bridge).
        $body = $driver->read('p-1')->body;
        $this->assertSame('Heading', $body['content'][0]['type']);
        $this->assertSame('Lyrics (edited on disk)', $body['content'][0]['props']['text']);
        $this->assertSame('ResourceList', $body['content'][1]['type']);
        $this->assertSame('library-lyrics', $body['content'][1]['props']['resource']);
    }

    public function test_round_trip_is_drift_free_modulo_id(): void
    {
        $seed = [
            'root' => [],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'h', 'text' => 'Lyrics']],
                ['type' => 'Prose', 'props' => ['id' => 'p', 'mdx' => "## Your words\n\nThe lyrics you've written."]],
                ['type' => 'ResourceList', 'props' => ['id' => 'r', 'resource' => 'library-lyrics']],
            ],
            'zones' => [],
        ];

        $tsx = (new PuckPageCodegen('@/puck/blocks'))->generate($seed, 'library-lyrics');
        $back = $this->app->make(PuckBridge::class)->toPuck($tsx);

        $this->assertNotNull($back);
        $this->assertSame($this->stripIds($seed), $this->stripIds($back));
    }

    public function test_register_from_disk_stores_a_page_tsx_as_puck_data_not_raw_source(): void
    {
        $root = sys_get_temp_dir().'/beamux-sync-'.uniqid();
        @mkdir($root.'/audiostud/page', 0777, true);

        $tsx = (new PuckPageCodegen('@/puck/blocks'))->generate([
            'root' => [],
            'content' => [['type' => 'Heading', 'props' => ['id' => 'h', 'text' => 'Lyrics']]],
            'zones' => [],
        ], 'library-lyrics');
        file_put_contents($root.'/audiostud/page/library-lyrics.tsx', $tsx);

        $this->app->make(RegisterEntriesFromDisk::class)->scan($root);

        $entry = BeamUxEntry::where('slug', 'library-lyrics')->firstOrFail();
        $this->assertSame(UxType::Page, $entry->type);

        // The body is Puck Data (has `content`), NOT the raw `['source' => …]` a component would get.
        $body = $this->app->make(StorageDriverResolver::class)->resolve($entry)->read((string) $entry->particle_id)->body;
        $this->assertArrayHasKey('content', $body);
        $this->assertArrayNotHasKey('source', $body);
        $this->assertSame('Heading', $body['content'][0]['type']);
        $this->assertSame('Lyrics', $body['content'][0]['props']['text']);

        $this->rrmdir($root);
    }

    public function test_disk_not_newer_is_left_untouched_last_writer(): void
    {
        RecordingSyncDriver::$diskIsNewer = false;

        $entry = BeamUxEntry::create([
            'slug' => 'library-lyrics', 'type' => UxType::Page, 'namespace' => 'audiostud',
            'format' => 'tsx', 'particle_id' => 'p-1',
        ]);
        $path = $this->app->make(PlacementResolver::class)->resolve($entry)->pathFor($entry);

        $result = $this->app->make(SyncPagesFromDisk::class)->run([$path => 'whatever'], [$path => 1]);
        $this->assertCount(0, $result['updated']);
    }

    /** @param array<string, mixed> $data */
    private function stripIds(array $data): array
    {
        $strip = function (array $node) use (&$strip): array {
            $props = [];
            foreach ($node['props'] ?? [] as $k => $v) {
                if ($k === 'id') {
                    continue;
                }
                $props[$k] = (is_array($v) && isset($v[0]['type'])) ? array_map($strip, $v) : $v;
            }

            return ['type' => $node['type'], 'props' => $props];
        };

        $data['content'] = array_map($strip, $data['content'] ?? []);

        return $data;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir.'/'.$e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
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
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}

/**
 * A recording {@see StorageDriver} standing in for the default Stacked(Particle, Disk) driver — needs no
 * beam-core particle table, so the sync test isolates BATCH behavior. `staleness` reports disk
 * newer/older per the static flag (last-writer).
 */
class RecordingSyncDriver implements StorageDriver
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
