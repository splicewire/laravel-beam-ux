<?php

namespace Splicewire\Beam\Ux\Recording;

use Symfony\Component\Process\Process;

/** Native hooks on an isolated HEAD/index; only a verified candidate may advance the captured ref. */
class LocalGit
{
    public readonly string $root;

    public readonly string $directory;

    public readonly string $common;

    public readonly string $branch;

    public function __construct(string $path)
    {
        $this->root = $this->run($path, ['rev-parse', '--show-toplevel']);
        $this->directory = $this->run($this->root, ['rev-parse', '--absolute-git-dir']);
        $this->common = $this->run($this->root, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $this->branch = $this->run($this->root, ['symbolic-ref', '-q', 'HEAD']);
        $this->assertSupported();
    }

    public function head(): string
    {
        return $this->run($this->root, ['rev-parse', '--verify', 'HEAD']);
    }

    public function assertSupported(): void
    {
        if ($this->run($this->root, ['rev-parse', '--is-bare-repository']) !== 'false'
            || $this->run($this->root, ['symbolic-ref', '-q', 'HEAD']) !== $this->branch) {
            throw new RecordingFailure('repository-state', 'Recording needs the original attached local branch.');
        }
        $this->head();
        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'BISECT_LOG', 'rebase-merge', 'rebase-apply', 'index.lock', 'HEAD.lock', 'config.worktree'] as $marker) {
            if (file_exists($this->directory.'/'.$marker)) {
                throw new RecordingFailure('repository-state', 'Finish the current Git operation before recording.');
            }
        }
        foreach (['extensions.worktreeConfig', 'core.splitIndex', 'core.sparseCheckout'] as $key) {
            $value = $this->optional(['config', '--bool', $key]);
            if ($value !== '' && $value !== 'false') {
                throw new RecordingFailure('unsupported-config', 'This Git worktree configuration is not supported for recording.');
            }
        }
        if (glob($this->directory.'/sharedindex.*') !== []) {
            throw new RecordingFailure('unsupported-index', 'Split indexes are not supported for recording.');
        }
    }

    /** @return resource */
    public function lock()
    {
        $handle = fopen($this->common.'/beam-ux-recording.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RecordingFailure('lock', 'The local recording lock is unavailable.');
        }

        return $handle;
    }

    /** Validate a frozen repository-relative regular-file destination, including new-file ancestors. */
    public function path(string $path): string
    {
        $parts = explode('/', $path);
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('/^[a-zA-Z]:/', $path) || str_starts_with($path, '/')) {
            throw new RecordingFailure('path', 'A Release mirror path is not a contained regular file.');
        }
        $cursor = $this->root;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new RecordingFailure('path', 'A Release mirror path is not a contained regular file.');
            }
            $cursor .= '/'.$part;
            if (is_dir($cursor) && file_exists($cursor.'/.git')) {
                throw new RecordingFailure('path', 'Nested Git worktrees cannot be recorded as member files.');
            }
            if (is_link($cursor) || (file_exists($cursor) && ! is_dir($cursor) && $cursor !== $this->root.'/'.$path)) {
                throw new RecordingFailure('path', 'Symlink or non-directory mirror ancestors cannot be recorded.');
            }
        }
        if (file_exists($cursor) && ! is_file($cursor)) {
            throw new RecordingFailure('path', 'Only regular mirror files can be recorded.');
        }
        // A parent tracked as a gitlink would otherwise accept a file inside another repository.
        $tracked = $this->run($this->root, ['ls-files', '--stage']);
        foreach (explode("\n", $tracked) as $row) {
            if (str_starts_with($row, '160000 ') && str_contains($row, "\t")) {
                $submodule = explode("\t", $row, 2)[1];
                if ($path === $submodule || str_starts_with($path, $submodule.'/')) {
                    throw new RecordingFailure('path', 'Submodule mirrors cannot be recorded.');
                }
            }
        }

        return $cursor;
    }

    public function relative(string $absolute): string
    {
        if (! str_starts_with($absolute, $this->root.'/')) {
            throw new RecordingFailure('path', 'All Release mirrors must belong to one Git worktree.');
        }
        $relative = substr($absolute, strlen($this->root) + 1);
        $this->path($relative);

        return $relative;
    }

    /** Prepare index from a fixed parent and exact bytes, never from a developer's staging area. */
    public function tree(string $work, string $base, array $manifest): string
    {
        $env = $this->environment($work, $base);
        $this->run($this->root, ['read-tree', $base], $env);
        foreach ($manifest as $file) {
            $bytes = base64_decode($file['bytes'], true);
            if ($bytes === false || hash('sha256', $bytes) !== $file['sha256']) {
                throw new RecordingFailure('manifest', 'The frozen recording manifest is invalid.');
            }
            $blob = $this->run($this->root, ['hash-object', '-w', '--stdin'], $env, $bytes);
            $this->run($this->root, ['update-index', '--add', '--cacheinfo', $file['mode'].','.$blob.','.$file['path']], $env);
        }

        return $this->run($this->root, ['write-tree'], $env);
    }

    public function commit(string $work, string $base, string $message): string
    {
        $env = $this->environment($work, $base);
        $this->run($this->root, ['commit', '-m', $message], $env);

        return $this->run($this->root, ['rev-parse', 'HEAD'], $env);
    }

    /** Verify the whole tree as well as the exact parent, member delta and durable identity. */
    public function verify(string $sha, string $base, string $tree, array $manifest, string $identity): void
    {
        $matching = $this->run($this->root, ['log', '--all', '--format=%H', '--fixed-strings', '--grep=Beam-UX-Recording: '.$identity]);
        foreach (array_filter(explode("\n", $matching)) as $match) {
            if ($match !== $sha) {
                throw new RecordingFailure('recovery-required', 'More than one Git candidate claims this recording identity.');
            }
        }
        if ($this->run($this->root, ['show', '-s', '--format=%P', $sha]) !== $base
            || $this->run($this->root, ['rev-parse', $sha.'^{tree}']) !== $tree
            || ! in_array('Beam-UX-Recording: '.$identity, explode("\n", $this->run($this->root, ['show', '-s', '--format=%B', $sha])), true)) {
            throw new RecordingFailure('recovery-required', 'The saved Git candidate does not match this recording.');
        }
        $paths = array_column($manifest, 'path');
        $changed = $this->run($this->root, ['diff-tree', '--no-commit-id', '--name-only', '-r', '-z', $sha], [], null, false);
        foreach (array_filter(explode("\0", $changed), fn ($path) => $path !== '') as $path) {
            if (! in_array($path, $paths, true)) {
                throw new RecordingFailure('recovery-required', 'A Git hook changed files outside this Release.');
            }
        }
        foreach ($manifest as $file) {
            $blob = $this->run($this->root, ['show', $sha.':'.$file['path']], [], null, false);
            $entry = $this->run($this->root, ['ls-tree', $sha, '--', $file['path']]);
            if (hash('sha256', $blob) !== $file['sha256'] || ! str_starts_with($entry, $file['mode'].' blob ')) {
                throw new RecordingFailure('recovery-required', 'A Git hook changed a frozen Release member.');
            }
        }
    }

    public function reachable(string $sha): bool
    {
        $process = $this->process($this->root, ['merge-base', '--is-ancestor', $sha, $this->branch]);
        $exit = $process->run();
        if ($exit > 1) {
            throw new RecordingFailure('recovery-required', 'The recorded Git result cannot be verified.');
        }

        return $exit === 0;
    }

    public function install(string $sha, string $base): void
    {
        $this->assertSupported();
        $this->run($this->root, ['update-ref', $this->branch, $sha, $base]);
    }

    public function anchor(string $identity, string $sha): void
    {
        $this->run($this->root, ['update-ref', 'refs/beam-ux-recordings/'.$identity, $sha]);
    }

    public function baseTree(string $base): string
    {
        return $this->run($this->root, ['rev-parse', $base.'^{tree}']);
    }

    /** Private detached HEAD remains a durable object anchor even when a result DB write fails. */
    private function environment(string $work, string $base): array
    {
        if (! is_dir($work) && ! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RecordingFailure('preparation', 'Local recording preparation is unavailable.');
        }
        if (! file_exists($work.'/HEAD')) {
            file_put_contents($work.'/HEAD', $base."\n");
            file_put_contents($work.'/commondir', $this->common."\n");
        }

        return ['GIT_DIR' => $work, 'GIT_COMMON_DIR' => $this->common, 'GIT_WORK_TREE' => $this->root, 'GIT_INDEX_FILE' => $work.'/index'];
    }

    private function optional(array $args): string
    {
        $process = $this->process($this->root, $args);
        $exit = $process->run();
        if ($exit > 1) {
            throw new RecordingFailure('repository-state', 'Git configuration cannot be verified.');
        }

        return trim($process->getOutput());
    }

    private function process(string $cwd, array $args, array $env = [], ?string $input = null): Process
    {
        // Explicitly clear inherited Git routing; hooks inherit only this operation's private routing.
        $clean = array_fill_keys(['GIT_DIR', 'GIT_COMMON_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_NAMESPACE'], false);

        return new Process([config('beam.ux.recording.git', 'git'), '--literal-pathspecs', ...$args], $cwd, array_merge($clean, $env), $input, 30);
    }

    private function run(string $cwd, array $args, array $env = [], ?string $input = null, bool $trim = true): string
    {
        try {
            $process = $this->process($cwd, $args, $env, $input);
            $process->mustRun();
        } catch (\Throwable $error) {
            throw new RecordingFailure('git-failed', 'Local Git recording failed. Check Git, repository state, identity, signing and hooks, then retry.');
        }

        return $trim ? trim($process->getOutput()) : $process->getOutput();
    }
}
