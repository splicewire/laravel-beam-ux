<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\GitRepo;
use Splicewire\Beam\Ux\Storage\GitRepoRegistrar;

/**
 * The actual point of mirror-status-ui ticket 02: N files under the SAME repo cost ~1 `git` process
 * spawn, not N — {@see MirrorGitStatus} used to shell `git status -- <path>` per file directly; this
 * class batches that by repo root instead. Real `git` (isolated temp trees, same discipline
 * {@see MirrorGitStatusTest} uses), never a mocked porcelain string.
 */
class GitRepoRegistrarTest extends TestCase
{
    private string $root;

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

        $this->root = sys_get_temp_dir().'/git-repo-registrar-'.uniqid();
        mkdir($this->root.'/page', 0777, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function git(array $args): void
    {
        Process::path($this->root)->run(['git', ...$args])->throw();
    }

    private function initRepo(): void
    {
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
    }

    public function test_resolving_two_files_under_the_same_repo_shares_one_row_and_one_refresh(): void
    {
        $this->initRepo();
        file_put_contents($this->root.'/page/a.tsx', 'a');
        file_put_contents($this->root.'/page/b.tsx', 'b');
        $this->git(['add', 'page/a.tsx', 'page/b.tsx']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $registrar = new GitRepoRegistrar;
        $first = $registrar->forFile($this->root.'/page/a.tsx');
        $second = $registrar->forFile($this->root.'/page/b.tsx');

        $this->assertSame($first->id, $second->id, 'both files resolve to the SAME repo row');
        $this->assertTrue($first->is($second), 'the second resolve reuses the in-process instance — no re-query, let alone a re-shell');
        $this->assertSame(1, GitRepo::query()->count(), 'one row per repo root, not one per file');

        // Prove the second resolve genuinely did NOT re-shell: mutate the real working tree (a new
        // untracked file) AFTER both resolves above, then resolve a THIRD file under the same repo,
        // still within the TTL — if forFile() re-shelled on every call, this would pick the new file
        // up; it doesn't, because the row is still fresh.
        file_put_contents($this->root.'/page/c.tsx', 'c');
        $third = $registrar->forFile($this->root.'/page/c.tsx');

        $this->assertSame([], $third->untracked_paths, 'still serving the cached (pre-mutation) snapshot within the TTL');
    }

    public function test_dirty_and_untracked_and_clean_are_all_correct_off_one_refresh(): void
    {
        $this->initRepo();
        file_put_contents($this->root.'/page/clean.tsx', 'clean');
        file_put_contents($this->root.'/page/dirty.tsx', 'v1');
        $this->git(['add', 'page/clean.tsx', 'page/dirty.tsx']);
        $this->git(['commit', '-q', '-m', 'seed']);
        file_put_contents($this->root.'/page/dirty.tsx', 'v2');
        file_put_contents($this->root.'/page/new.tsx', 'new');

        $registrar = new GitRepoRegistrar;
        $repo = $registrar->forFile($this->root.'/page/clean.tsx');

        $this->assertSame(['page/dirty.tsx'], $repo->dirty_paths);
        $this->assertSame(['page/new.tsx'], $repo->untracked_paths);
        $this->assertContains('page/clean.tsx', $repo->tracked_paths);
        $this->assertContains('page/dirty.tsx', $repo->tracked_paths);
        $this->assertNotContains('page/new.tsx', $repo->tracked_paths);
        $this->assertNotNull($repo->head_sha);
        $this->assertNotNull($repo->branch);
    }

    public function test_no_git_repo_returns_null_without_creating_a_row(): void
    {
        // A fresh isolated dir with no .git anywhere above it and no ancestor repo.
        $bare = sys_get_temp_dir().'/git-repo-registrar-bare-'.uniqid();
        mkdir($bare, 0777, true);

        try {
            $registrar = new GitRepoRegistrar;
            $repo = $registrar->forFile($bare.'/whatever.tsx');

            $this->assertNull($repo);
            $this->assertSame(0, GitRepo::query()->count());
        } finally {
            File::deleteDirectory($bare);
        }
    }

    public function test_a_stale_row_is_refreshed_on_the_next_resolve(): void
    {
        $this->initRepo();
        file_put_contents($this->root.'/page/a.tsx', 'a');

        $registrar = new GitRepoRegistrar;
        $first = $registrar->forFile($this->root.'/page/a.tsx');
        $this->assertSame(['page/a.tsx'], $first->untracked_paths);

        // Force staleness directly (bypassing the 5s TTL) rather than sleeping the test.
        $first->forceFill(['checked_at' => now()->subSeconds(GitRepoRegistrar::TTL_SECONDS + 1)])->save();

        $this->git(['add', 'page/a.tsx']);
        $this->git(['commit', '-q', '-m', 'seed']);

        // A FRESH registrar instance (a new request would get a fresh one too) — its in-process cache
        // is empty, so this exercises the DB-row staleness check, not the in-memory one.
        $refreshed = (new GitRepoRegistrar)->forFile($this->root.'/page/a.tsx');

        $this->assertSame([], $refreshed->untracked_paths);
        $this->assertSame([], $refreshed->dirty_paths);
        $this->assertContains('page/a.tsx', $refreshed->tracked_paths);
    }
}
