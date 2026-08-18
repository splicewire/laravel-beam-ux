<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Storage\GitRepoRegistrar;
use Splicewire\Beam\Ux\Storage\MirrorGitStatus;

/**
 * `Storage::fake()` is backed by a REAL local temp directory (not an in-memory mock), so `git init`
 * against its root gives every state below a genuine `git` verdict, not a hand-simulated one — the
 * one thing this class exists to get right honestly (a repo root that isn't the mirror disk's own
 * root, a file with no repo at all, tracked-vs-ignored). `MirrorGitStatus` now resolves that verdict
 * through {@see GitRepoRegistrar} (mirror-status-ui ticket 02) instead of shelling per file itself —
 * see {@see GitRepoRegistrarTest} for the batching behavior itself; this file stays about the STATE
 * VOCABULARY, unchanged by that split.
 */
class MirrorGitStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('git_repos', function (Blueprint $table) {
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

    private function mirrorStatus(?FilesystemAdapter $disk): MirrorGitStatus
    {
        return new MirrorGitStatus($disk, new GitRepoRegistrar);
    }

    private function git(string $root, array $args): void
    {
        Process::path($root)->run(['git', ...$args])->throw();
    }

    private function initRepo(string $root): void
    {
        $this->git($root, ['init', '-q']);
        $this->git($root, ['config', 'user.email', 'test@example.com']);
        $this->git($root, ['config', 'user.name', 'Test']);
    }

    public function test_disabled_when_no_disk_configured(): void
    {
        $status = $this->mirrorStatus(null);
        $this->assertFalse($status->enabled());
        $this->assertSame('mirror-disabled', $status->statusFor('anything.tsx')['state']);
    }

    public function test_not_written_when_the_file_does_not_exist(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('mirror-git-status');
        $status = $this->mirrorStatus($disk);

        $result = $status->statusFor('page/nope.tsx');

        $this->assertFalse($result['exists']);
        $this->assertSame('not-written', $result['state']);
    }

    public function test_no_git_repo_when_nothing_above_the_file_has_a_dot_git(): void
    {
        // Storage::fake()'s temp root lives under vendor/orchestra/... INSIDE this package's own git
        // repo, so walking up from it WOULD find a (gitignored) .git — not what this test means to
        // isolate. A directory built directly under the system temp root has no such ancestor.
        $isolated = sys_get_temp_dir().'/mirror-git-status-nogit-'.uniqid();
        mkdir($isolated.'/page', 0777, true);

        try {
            file_put_contents($isolated.'/page/beam.tsx', 'export default () => null;');
            $disk = Storage::build(['driver' => 'local', 'root' => $isolated]);

            $result = $this->mirrorStatus($disk)->statusFor('page/beam.tsx');

            $this->assertTrue($result['exists']);
            $this->assertSame('no-git-repo', $result['state']);
        } finally {
            File::deleteDirectory($isolated);
        }
    }

    public function test_clean_when_the_file_is_committed_and_unchanged(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('mirror-git-status');
        $disk->put('page/beam.tsx', 'export default () => null;');
        $root = $disk->path('');

        $this->initRepo($root);
        $this->git($root, ['add', 'page/beam.tsx']);
        $this->git($root, ['commit', '-q', '-m', 'seed']);

        $result = $this->mirrorStatus($disk)->statusFor('page/beam.tsx');

        $this->assertSame('clean', $result['state']);
        $this->assertNotNull($result['lastModifiedAt']);
    }

    public function test_modified_when_a_tracked_file_has_uncommitted_changes(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('mirror-git-status');
        $disk->put('page/beam.tsx', 'export default () => null;');
        $root = $disk->path('');

        $this->initRepo($root);
        $this->git($root, ['add', 'page/beam.tsx']);
        $this->git($root, ['commit', '-q', '-m', 'seed']);

        $disk->put('page/beam.tsx', 'export default () => "changed";');

        $result = $this->mirrorStatus($disk)->statusFor('page/beam.tsx');

        $this->assertSame('modified', $result['state']);
    }

    public function test_untracked_when_the_file_was_never_added(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('mirror-git-status');
        $root = $disk->path('');
        $this->initRepo($root);

        $disk->put('page/new.tsx', 'export default () => null;');

        $result = $this->mirrorStatus($disk)->statusFor('page/new.tsx');

        $this->assertSame('untracked', $result['state']);
    }

    public function test_untracked_ignored_when_git_reports_nothing_but_the_file_is_not_tracked(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('mirror-git-status');
        $root = $disk->path('');
        $this->initRepo($root);

        $disk->put('.gitignore', 'page/ignored.tsx'.PHP_EOL);
        $this->git($root, ['add', '.gitignore']);
        $this->git($root, ['commit', '-q', '-m', 'ignore rule']);
        $disk->put('page/ignored.tsx', 'export default () => null;');

        $result = $this->mirrorStatus($disk)->statusFor('page/ignored.tsx');

        $this->assertSame('untracked-ignored', $result['state']);
    }

    public function test_finds_the_repo_root_above_the_mirror_disks_own_root(): void
    {
        // The mirror disk's root is a SUBDIRECTORY of the repo, not the repo root itself — the
        // service must walk up, not assume disk-root === repo-root. Uses a FULLY isolated temp tree
        // (not Storage::fake(), which shares its parent directory with every other fake disk in the
        // suite) so planting a .git one level up can't leak into a sibling test's fake disk.
        $repoRoot = sys_get_temp_dir().'/mirror-git-status-'.uniqid();
        $diskRoot = $repoRoot.'/resources/content';
        mkdir($diskRoot, 0777, true);

        try {
            file_put_contents($diskRoot.'/beam.tsx', 'export default () => null;');
            $this->initRepo($repoRoot);
            $this->git($repoRoot, ['add', '.']);
            $this->git($repoRoot, ['commit', '-q', '-m', 'seed']);

            $disk = Storage::build(['driver' => 'local', 'root' => $diskRoot]);
            $result = $this->mirrorStatus($disk)->statusFor('beam.tsx');

            $this->assertSame('clean', $result['state']);
        } finally {
            File::deleteDirectory($repoRoot);
        }
    }
}
