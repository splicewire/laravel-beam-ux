<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

/**
 * The git-state half of "what did the disk mirror actually do" — {@see PlacedDiskMirror} writes a
 * file; this reads back whether it exists, when it last changed, and its git state, WITHOUT assuming
 * the mirror disk's own root IS a git repo root (`beam.ux.storage.mirror_disk`'s root, e.g.
 * `resources/js/content`, is typically a SUBDIRECTORY of the app's real repo root) — walks up from the
 * file looking for `.git` instead.
 *
 * **Degrade-not-fabricate**, same as `PlacedDiskMirror`: no mirror disk configured → every call
 * short-circuits to `mirror-disabled` with no filesystem/process work attempted at all. A file that
 * exists but has no discoverable `.git` above it reports `no-git-repo` honestly rather than silently
 * omitting git state or guessing.
 */
class MirrorGitStatus
{
    public function __construct(private ?Filesystem $disk) {}

    /** Is a mirror disk configured? Mirrors {@see PlacedDiskMirror::enabled()}. */
    public function enabled(): bool
    {
        return $this->disk !== null;
    }

    /**
     * @return array{path: string, exists: bool, lastModifiedAt: ?string, state: string}
     */
    public function statusFor(string $relativePath): array
    {
        if ($this->disk === null) {
            return $this->result($relativePath, false, null, 'mirror-disabled');
        }

        if (! $this->disk->exists($relativePath)) {
            return $this->result($relativePath, false, null, 'not-written');
        }

        $lastModifiedAt = $this->disk->lastModified($relativePath);
        $lastModifiedIso = $lastModifiedAt !== false
            ? date(DATE_ATOM, $lastModifiedAt)
            : null;

        $absolute = $this->disk->path($relativePath);
        $repoRoot = $this->findGitRoot(dirname($absolute));

        if ($repoRoot === null) {
            return $this->result($relativePath, true, $lastModifiedIso, 'no-git-repo');
        }

        return $this->result($relativePath, true, $lastModifiedIso, $this->gitState($repoRoot, $absolute));
    }

    /** Walk up from `$dir` looking for a `.git` directory; `null` when none is found before the
     * filesystem root. */
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

    /** `modified` (tracked, uncommitted changes) / `untracked` (porcelain says new) / `clean`
     * (nothing in the porcelain output, and `git ls-files` confirms it's actually tracked) /
     * `untracked-ignored` (nothing in the porcelain output, but NOT tracked — e.g. gitignored). A
     * `git` invocation that fails outright (binary missing, not actually a repo despite `.git`
     * existing) degrades to `no-git-repo` rather than throwing. */
    private function gitState(string $repoRoot, string $absolutePath): string
    {
        $status = Process::path($repoRoot)->run(['git', 'status', '--porcelain', '--', $absolutePath]);

        if ($status->failed()) {
            return 'no-git-repo';
        }

        $output = trim($status->output());

        if ($output === '') {
            $tracked = Process::path($repoRoot)->run(['git', 'ls-files', '--error-unmatch', '--', $absolutePath]);

            return $tracked->successful() ? 'clean' : 'untracked-ignored';
        }

        return str_starts_with($output, '??') ? 'untracked' : 'modified';
    }

    /** @return array{path: string, exists: bool, lastModifiedAt: ?string, state: string} */
    private function result(string $path, bool $exists, ?string $lastModifiedAt, string $state): array
    {
        return ['path' => $path, 'exists' => $exists, 'lastModifiedAt' => $lastModifiedAt, 'state' => $state];
    }
}
