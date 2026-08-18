<?php

namespace Splicewire\Beam\Ux\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Splicewire\Beam\Models\GitRepo;
use Splicewire\Beam\Storage\GitRepoRegistrar;

/**
 * The git-state half of "what did the disk mirror actually do" — {@see PlacedDiskMirror} writes a
 * file; this reads back whether it exists, when it last changed, and its git state.
 *
 * **Degrade-not-fabricate**, same as `PlacedDiskMirror`: no mirror disk configured → every call
 * short-circuits to `mirror-disabled` with no filesystem/process work attempted at all. A file that
 * exists but has no discoverable `.git` above it reports `no-git-repo` honestly rather than silently
 * omitting git state or guessing.
 *
 * The actual git work — walking up for `.git`, running `git status`/`git ls-files` — lives in
 * {@see GitRepoRegistrar} now (mirror-status-ui ticket 02): this class resolves the file's
 * {@see GitRepo} and looks its relative path up in that repo's cached dirty/untracked/tracked sets,
 * instead of shelling a process per file itself. Public contract (this method's signature and every
 * state name) is UNCHANGED from before that split — no caller needed to change.
 */
class MirrorGitStatus
{
    public function __construct(private ?Filesystem $disk, private GitRepoRegistrar $repos) {}

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
        $repo = $this->repos->forFile($absolute);

        if ($repo === null) {
            return $this->result($relativePath, true, $lastModifiedIso, 'no-git-repo');
        }

        return $this->result($relativePath, true, $lastModifiedIso, $this->stateFor($repo, $absolute));
    }

    /** `modified` (in the repo's dirty set) / `untracked` (in its untracked set) / `clean` (in
     * neither, but IS in the tracked set) / `untracked-ignored` (in neither, and NOT tracked — e.g.
     * gitignored). */
    private function stateFor(GitRepo $repo, string $absolutePath): string
    {
        $relative = $this->relativeToRoot($repo->root_path, $absolutePath);

        if (in_array($relative, $repo->untracked_paths, true)) {
            return 'untracked';
        }

        if (in_array($relative, $repo->dirty_paths, true)) {
            return 'modified';
        }

        return in_array($relative, $repo->tracked_paths, true) ? 'clean' : 'untracked-ignored';
    }

    private function relativeToRoot(string $root, string $absolutePath): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return ltrim(substr($absolutePath, strlen($root)), DIRECTORY_SEPARATOR);
    }

    /** @return array{path: string, exists: bool, lastModifiedAt: ?string, state: string} */
    private function result(string $path, bool $exists, ?string $lastModifiedAt, string $state): array
    {
        return ['path' => $path, 'exists' => $exists, 'lastModifiedAt' => $lastModifiedAt, 'state' => $state];
    }
}
