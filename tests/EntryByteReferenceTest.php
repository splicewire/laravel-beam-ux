<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Symfony\Component\Process\Process;

/**
 * config-sections 08 gate test 2 — the entry-only recording is byte-identical across the receipt-driven complete()
 * refactor. Uses ONLY code present before 08 (no config classes), so it runs on BOTH ux-08-base (reference) and
 * ux-08-cand. Publishes a FIXED entry-only Release and writes the commit's TREE HASH + message to BEAM_BYTE_REF_OUT; the
 * compare script asserts the base and candidate TREE HASHES are equal (the byte check — the committed file content) and
 * logs both messages (the trailer format is identical; its receipt id is a per-run uuid and is expected to differ).
 */
class EntryByteReferenceTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $root = getenv('BEAM_RECORDING_TEST_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            $this->markTestSkipped('Set BEAM_RECORDING_TEST_ROOT to an owned registered scratch root.');
        }
        $this->repo = $root.'/byteref-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0700);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.name', 'Release test']);
        $this->git(['config', 'user.email', 'release@example.invalid']);
        file_put_contents($this->repo.'/unrelated', "base\n");
        $this->git(['add', '--', 'unrelated']);
        $this->git(['commit', '-m', 'baseline']);
        file_put_contents($this->repo.'/.git/hooks/pre-commit', "#!/bin/sh\nexit 0\n");
        chmod($this->repo.'/.git/hooks/pre-commit', 0755);
        config(['filesystems.disks.recording-test' => ['driver' => 'local', 'root' => $this->repo, 'throw' => true],
            'beam.ux.storage.mirror_disk' => 'recording-test', 'beam.ux.recording.git' => 'git']);
        Storage::forgetDisk('recording-test');
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Storage\PlacedDiskMirror::class);
        $this->app->forgetInstance(EntryPublication::class);

        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table', 'create_beam_ux_release_recordings_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        Gate::before(fn () => true);
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
    }

    public function test_entry_only_commit_tree_and_message(): void
    {
        $out = getenv('BEAM_BYTE_REF_OUT');
        if (! is_string($out) || $out === '') {
            $this->markTestSkipped('Set BEAM_BYTE_REF_OUT to the reference-capture path.');
        }
        // A FIXED page body, so the mirrored file bytes (and therefore the commit tree) are reproducible across trees.
        $page = BeamUxEntry::create(['slug' => 'byteref', 'title' => 'ByteRef', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'byteref']);
        $version = (string) app(EntryPublication::class)->recordDraft($page->fresh(), [
            ['kind' => 'block', 'name' => 'section', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []],
        ])->headVersion;
        $release = app(ReleaseService::class)->requestOne($page->fresh(), $version, $this->author);
        $this->assertSame('published', $release->status);

        $receipt = app(ReleaseRecording::class)->receipt($release);
        $this->assertSame('recorded', $receipt->state, (string) $receipt->error);
        $tree = $this->git(['rev-parse', $receipt->sha.'^{tree}']);
        $message = $this->git(['log', '-1', '--format=%B', $receipt->sha]);
        file_put_contents($out, "tree={$tree}\nmessage=\n{$message}\n");
        $this->assertNotEmpty($tree);
    }

    private function git(array $args): string
    {
        $process = new Process(['git', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
