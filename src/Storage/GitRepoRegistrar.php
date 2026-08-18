<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Support\Facades\Process;
use Splicewire\Beam\Ux\Models\GitRepo;

/**
 * Resolves "the nearest git repo to this file" and auto-materializes it as a {@see GitRepo} row
 * (mirror-status-ui ticket 02) — the batching fix for {@see MirrorGitStatus}'s original per-file
 * `git status -- <path>` shape. Every `BeamUxEntry` a host mirrors typically lives under the SAME
 * repo root, so N entries asking "what's my git state" should cost ~1 process spawn per repo, not N:
 * `forFile()` walks up for `.git`, `firstOrCreate`s the row keyed by the canonical root, and refreshes
 * it (one repo-wide `git status --porcelain` + `git ls-files`, no path filter) only when stale — every
 * OTHER file under the same root within the TTL window reads the already-cached row.
 *
 * Two cache layers, deliberately: the in-PROCESS `$resolved` array means N calls for the same repo
 * within ONE request never re-hit the DB either; the DB row's `checked_at` TTL means a burst of
 * requests (e.g. an operator refreshing the mirror-status tool repeatedly) doesn't re-shell `git` on
 * every single one.
 */
class GitRepoRegistrar
{
    /** A cached row older than this is re-shelled on next resolve; never blocks a resolve on a miss. */
    public const TTL_SECONDS = 5;

    /** @var array<string, GitRepo> */
    private array $resolved = [];

    /**
     * The known {@see GitRepo} for the repo containing `$absolutePath`, refreshing it first if stale.
     * `null` when no `.git` is found walking up from the file (mirrors
     * {@see MirrorGitStatus}'s own `no-git-repo` degrade — never throws).
     */
    public function forFile(string $absolutePath): ?GitRepo
    {
        $root = $this->findGitRoot(dirname($absolutePath));

        if ($root === null) {
            return null;
        }

        if (isset($this->resolved[$root])) {
            return $this->resolved[$root];
        }

        $repo = GitRepo::query()->firstOrCreate(
            ['root_path' => $root],
            ['dirty_paths' => [], 'untracked_paths' => [], 'tracked_paths' => []],
        );

        if ($this->isStale($repo)) {
            $this->refresh($repo);
        }

        return $this->resolved[$root] = $repo;
    }

    private function isStale(GitRepo $repo): bool
    {
        return $repo->checked_at === null
            || $repo->checked_at->diffInSeconds(now()) > self::TTL_SECONDS;
    }

    /** One `git status --porcelain` + `git ls-files`, both repo-wide (no path filter) — the batching. */
    private function refresh(GitRepo $repo): void
    {
        [$dirty, $untracked] = $this->parsePorcelain($repo->root_path);

        $repo->fill([
            'dirty_paths' => $dirty,
            'untracked_paths' => $untracked,
            'tracked_paths' => $this->trackedPaths($repo->root_path),
            'branch' => $this->run($repo->root_path, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']) ?: null,
            'head_sha' => $this->run($repo->root_path, ['git', 'rev-parse', 'HEAD']) ?: null,
            'checked_at' => now(),
        ])->save();
    }

    /** @return array{0: list<string>, 1: list<string>} [dirty, untracked] */
    private function parsePorcelain(string $repoRoot): array
    {
        // `--untracked-files=all`: without it, git collapses a wholly-untracked DIRECTORY into one
        // `?? page/` entry instead of listing each file inside it — found live, the reason a fresh
        // untracked file inside a not-yet-tracked directory reported `untracked-ignored` (silent in
        // porcelain, so it fell through to the tracked-set check) instead of `untracked`.
        $output = $this->run($repoRoot, ['git', 'status', '--porcelain', '--untracked-files=all']);
        $dirty = [];
        $untracked = [];

        if ($output === null || $output === '') {
            return [$dirty, $untracked];
        }

        foreach (preg_split('/\r?\n/', $output) as $line) {
            if ($line === '') {
                continue;
            }

            $code = substr($line, 0, 2);
            $path = ltrim(substr($line, 2));

            // A rename line reads "old -> new" — the working path is what's THERE now.
            if (($pos = strpos($path, ' -> ')) !== false) {
                $path = substr($path, $pos + 4);
            }

            if (str_starts_with($code, '??')) {
                $untracked[] = $path;
            } else {
                $dirty[] = $path;
            }
        }

        return [$dirty, $untracked];
    }

    /** @return list<string> */
    private function trackedPaths(string $repoRoot): array
    {
        $output = $this->run($repoRoot, ['git', 'ls-files']);

        if ($output === null || $output === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\r?\n/', $output), fn ($p) => $p !== ''));
    }

    /**
     * Run a git command scoped to `$repoRoot`. `null` on actual failure (missing binary, not really a
     * repo despite `.git` existing) — distinct from `''`, a genuine success with no output (a clean
     * repo's `git status --porcelain`, an empty repo's `git ls-files`). Refresh degrades to empty
     * state on `null`, never throws.
     *
     * @param  list<string>  $command
     */
    private function run(string $repoRoot, array $command): ?string
    {
        $result = Process::path($repoRoot)->run($command);

        if ($result->failed()) {
            return null;
        }

        return trim($result->output());
    }

    /** Walk up from `$dir` looking for a `.git` directory; `null` when none is found before the
     * filesystem root. Same walk {@see MirrorGitStatus} used directly before this class existed. */
    private function findGitRoot(string $dir): ?string
    {
        while ($dir !== '' && $dir !== DIRECTORY_SEPARATOR) {
            if (is_dir($dir.DIRECTORY_SEPARATOR.'.git')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }
}
