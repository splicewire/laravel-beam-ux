<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `MirrorStatusRowData::project()` end to end (mirror-status-ui ticket 01, retrofitted to
 * `#[ParticleResource]` per the particle doctrine) — a real `BeamUxEntry` row, a real placement path
 * resolved through the same `PlacementResolver` the mirror write path uses, and a real file on disk,
 * proving the projection wires `PlacementResolver` + `MirrorGitStatus` correctly, not just that each
 * collaborator works in isolation (already covered by {@see MirrorGitStatusTest}). This class used to
 * be exercised through a bespoke `BeamUxMirrorStatusController`; that controller and its
 * `#[ResponseFromData]` envelope are gone — the annotated Data class itself IS the resource now, served
 * by the generic `ParticleController`/`FrameResourceController`, so this test calls `project()` directly
 * rather than a route.
 */
class MirrorStatusRowDataTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $this->root = sys_get_temp_dir().'/beamux-mirror-status-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    public function test_an_entry_with_no_particle_id_reports_not_yet_saved_without_touching_the_disk(): void
    {
        $this->configureMirrorDisk();

        $entry = BeamUxEntry::create(['slug' => 'draft', 'type' => 'page', 'namespace' => null]);

        $row = MirrorStatusRowData::project($entry);

        $this->assertSame('not-yet-saved', $row->state);
        $this->assertNull($row->path);
    }

    public function test_a_saved_entry_resolves_the_same_path_the_mirror_writer_would_use_and_reports_its_git_state(): void
    {
        $this->configureMirrorDisk();

        $entry = BeamUxEntry::create([
            'slug' => 'beam',
            'type' => 'page',
            'namespace' => null,
            'particle_id' => (string) Str::uuid(),
        ]);

        mkdir($this->root.'/page', 0777, true);
        file_put_contents($this->root.'/page/beam.tsx', 'export default () => null;');

        $row = MirrorStatusRowData::project($entry);

        $this->assertSame((string) $entry->id, $row->id);
        $this->assertSame('page/beam.tsx', $row->path);
        $this->assertTrue($row->exists);
        // No .git above the isolated temp root, so this is the honest verdict — the point of this
        // assertion is that `project()` reached MirrorGitStatus at all, not which state it landed on.
        $this->assertSame('no-git-repo', $row->state);
    }

    public function test_a_saved_entry_degrades_to_mirror_disabled_when_no_mirror_disk_configured(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'beam',
            'type' => 'page',
            'namespace' => null,
            'particle_id' => (string) Str::uuid(),
        ]);

        $row = MirrorStatusRowData::project($entry);

        $this->assertSame('mirror-disabled', $row->state);
        $this->assertFalse($row->exists);
    }

    private function configureMirrorDisk(): void
    {
        Config::set('beam.ux.storage.mirror_disk', 'beam-ux-mirror-status-test');
        Config::set('filesystems.disks.beam-ux-mirror-status-test', [
            'driver' => 'local',
            'root' => $this->root,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
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
            $table->string('segment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('git_repos'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('root_path')->unique();
            $table->string('branch')->nullable();
            $table->string('head_sha')->nullable();
            $table->json('dirty_paths');
            $table->json('untracked_paths');
            $table->json('tracked_paths');
            $table->timestamp('checked_at')->nullable();
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
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
