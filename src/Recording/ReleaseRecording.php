<?php

namespace Splicewire\Beam\Ux\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseRecording;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;

/** Publication completion and retry share one frozen receipt and the same all-member authority. */
class ReleaseRecording
{
    public function receipt(BeamUxRelease $release): ?BeamUxReleaseRecording
    {
        if (! $release->getConnection()->getSchemaBuilder()->hasTable('beam_ux_release_recordings')) {
            return null;
        }

        return BeamUxReleaseRecording::on($release->getConnectionName())->where('release_id', $release->id)->first();
    }

    /** History needs status only; never hydrate frozen file bodies into a version-list projection. */
    public function receiptSummary(BeamUxRelease $release): ?BeamUxReleaseRecording
    {
        if (! $release->getConnection()->getSchemaBuilder()->hasTable('beam_ux_release_recordings')) {
            return null;
        }

        return BeamUxReleaseRecording::on($release->getConnectionName())
            ->where('release_id', $release->id)
            ->first(['id', 'release_id', 'state', 'sha', 'no_change', 'error']);
    }

    /** Called inside publication; optional projection failure is contained in its own savepoint. */
    public function prepare(BeamUxRelease $release, array $prepared, Authenticatable $actor, array $takedowns = []): void
    {
        $manifest = [];
        $failure = null;
        try {
            $disk = $this->disk();
            $seen = [];
            $size = 0;
            foreach ($prepared as [$entry, $version, $body]) {
                $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
                $bytes = $entry->codec()->decode($body);
                $absolute = $disk->path($path);
                $this->contained($disk->path(''), $path);
                $size += strlen($bytes);
                if ($size > 4194304 || isset($seen[strtolower($absolute)])) {
                    throw new RecordingFailure('manifest', 'The Release mirror manifest is too large or contains duplicate paths.');
                }
                $seen[strtolower($absolute)] = true;
                $manifest[] = ['entryId' => (string) $entry->id, 'versionId' => (string) $version->id,
                    'codec' => $entry->codec()::class, 'path' => $path, 'absolute' => $absolute,
                    'mode' => is_file($absolute) && is_executable($absolute) ? '100755' : '100644',
                    'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
            }
            foreach ($takedowns as [$entry, $version, $body]) {
                $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
                $bytes = $entry->codec()->decode($body);
                $absolute = $disk->path($path);
                $this->contained($disk->path(''), $path);
                $size += strlen($bytes);
                if ($size > 4194304 || isset($seen[strtolower($absolute)]) || ! is_file($absolute)
                    || hash_file('sha256', $absolute) !== hash('sha256', $bytes)) {
                    throw new RecordingFailure('mirror-drift', 'A Take-down mirror differs from the currently published bytes.');
                }
                $seen[strtolower($absolute)] = true;
                $manifest[] = ['action' => 'delete', 'entryId' => (string) $entry->id, 'versionId' => (string) $version->id,
                    'codec' => $entry->codec()::class, 'path' => $path, 'absolute' => $absolute,
                    'mode' => is_executable($absolute) ? '100755' : '100644',
                    'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
            }
        } catch (\Throwable $error) {
            $failure = $error instanceof RecordingFailure ? $error->reason : 'preparation';
        }
        try {
            $release->getConnection()->transaction(function () use ($release, $manifest, $failure) {
                $row = (new BeamUxReleaseRecording)->setConnection($release->getConnectionName());
                $row->forceFill(['release_id' => $release->id, 'manifest' => $manifest,
                    'state' => $failure === null ? 'pending' : 'unavailable', 'error' => $failure])->save();
            });
        } catch (\Throwable) {
            // Without a durable receipt, completion may mirror but MUST NOT run Git.
        }
        $snapshot = clone $release;
        $release->getConnection()->afterCommit(function () use ($snapshot, $prepared, $takedowns, $actor) {
            try {
                $this->complete($snapshot->fresh(), $prepared, $takedowns, $actor);
            } catch (\Throwable) {
                // A post-commit optional side effect cannot become a failed-publication HTTP result.
                $this->failure($snapshot, 'completion');
            }
            foreach (array_merge($prepared, $takedowns) as [$entry]) {
                try {
                    app(\Splicewire\Beam\Ux\Compile\CompileEntryBody::class)->artifacts()->prune($entry->fresh());
                } catch (\Throwable) {
                    // Retention failure is independent of publication/mirroring/recording.
                }
            }
        });
    }

    private function complete(BeamUxRelease $release, array $prepared, array $takedowns, Authenticatable $actor): void
    {
        $git = null;
        $lock = null;
        $reason = 'git-failed';
        $diagnostic = null;
        // Match publication's global entry-ID lock order before SQLite reservation or row reads.
        $allMembers = array_merge($prepared, $takedowns);
        usort($allMembers, fn (array $left, array $right): int => strcmp((string) $left[0]->getKey(), (string) $right[0]->getKey()));
        $preparedIds = array_fill_keys(array_map(fn (array $item): string => (string) $item[0]->getKey(), $prepared), true);
        try {
            try {
                $this->authorize($release, $actor);
                $receipt = $this->receipt($release);
                if ($receipt === null || $receipt->state === 'unavailable' || count($receipt->manifest) !== $release->members()->count()) {
                    throw new RecordingFailure('unavailable', 'No durable recording receipt is available.');
                }
                $git = new LocalGit($this->disk()->path(''));
                $lock = $git->lock();
                foreach ($receipt->manifest as $file) {
                    $this->contained($this->disk()->path(''), $file['path']);
                    $git->relative($file['absolute']);
                }
            } catch (RecordingFailure|\Illuminate\Auth\Access\AuthorizationException $error) {
                $git = null;
                $reason = $error instanceof RecordingFailure ? $error->reason : 'unavailable';
                $diagnostic = $error instanceof RecordingFailure ? $error->diagnostic() : null;
                // Mirror publication remains supported without Git.
            }
            // Keep entry rows locked while completing mirrors; a concurrent publication cannot move
            // their pins between this stale check and the writes. Git does not hold a DB transaction.
            $release->getConnection()->transaction(function () use ($preparedIds, $takedowns, $allMembers, $release) {
                // Reserve SQLite before pin reads; other drivers retain the existing sorted row locks.
                if ($allMembers !== [] && $release->getConnection()->getDriverName() === 'sqlite') {
                    $first = reset($allMembers)[0];
                    $first->newQuery()->whereKey($first->getKey())->toBase()->update(['id' => $first->getKey()]);
                }
                foreach ($allMembers as [$entry, $version]) {
                    $current = $entry->newQuery()->lockForUpdate()->find($entry->id);
                    if (isset($preparedIds[(string) $entry->getKey()])) {
                        if ($current === null || $current->published_version !== (string) $version->id
                            || $current->workflow_marking !== $entry->workflow_marking) {
                            throw new RecordingFailure('superseded', 'A newer publication superseded this mirror completion.');
                        }
                    } elseif ($current === null || $current->published_version !== (string) $version->id
                        || $current->workflow_marking === BeamUxEntry::MARKING_PUBLISHED) {
                        throw new RecordingFailure('superseded', 'A newer publication superseded this Take-down completion.');
                    }
                }
                $diskName = config('beam.ux.storage.mirror_disk');
                $disk = is_string($diskName) && $diskName !== '' ? Storage::disk($diskName) : null;
                if ($disk === null) {
                    throw new RecordingFailure('mirror-disabled', 'No mirror disk is enabled.');
                }
                $local = config('filesystems.disks.'.$diskName.'.driver') === 'local';
                if ($takedowns !== [] && ! $local) {
                    throw new RecordingFailure('mirror-disabled', 'Take-down recording needs an enabled local mirror disk.');
                }
                foreach ($allMembers as $member) {
                    if (! isset($preparedIds[(string) $member[0]->getKey()])) {
                        continue;
                    }
                    [$entry, $version, $body] = $member;
                    $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
                    if ($local) {
                        $this->mirrorContained($disk->path(''), $path);
                    } else {
                        // Remote mirrors remain valid publication targets; only local Git recording
                        // and deletion receipts require filesystem paths.
                        $this->relativeParts($path);
                    }
                    if (! app(PlacedDiskMirror::class)->mirror($entry, $path, $body)) {
                        throw new RecordingFailure('mirror', 'A Release mirror could not be completed.');
                    }
                    $actual = $disk->get($path);
                    if ($actual !== $entry->codec()->decode($body)) {
                        throw new RecordingFailure('mirror', 'A Release mirror did not match its published snapshot.');
                    }
                }
                if ($takedowns !== []) {
                    $receipt = $this->receipt($release);
                    if ($receipt === null || $receipt->state === 'unavailable') {
                        return;
                    }
                    foreach ($allMembers as $member) {
                        if (isset($preparedIds[(string) $member[0]->getKey()])) {
                            continue;
                        }
                        [$entry] = $member;
                        $file = collect($receipt?->manifest ?? [])->first(fn (array $item) => ($item['action'] ?? null) === 'delete'
                            && $item['entryId'] === (string) $entry->id);
                        if ($file === null) {
                            throw new RecordingFailure('manifest', 'The Take-down is missing its frozen mirror receipt.');
                        }
                        $path = $file['path'];
                        $this->mirrorContained($disk->path(''), $path);
                        if (! is_file($disk->path($path)) || hash_file('sha256', $disk->path($path)) !== $file['sha256']
                            || (is_executable($disk->path($path)) ? '100755' : '100644') !== $file['mode']) {
                            throw new RecordingFailure('mirror-drift', 'A Take-down mirror differs from the frozen published bytes.');
                        }
                        if (! $disk->delete($path) || $disk->exists($path)) {
                            throw new RecordingFailure('mirror', 'A Take-down mirror could not be removed.');
                        }
                    }
                    $receipt?->forceFill(['state' => 'mirror-deleted'])->save();
                }
            });
            if ($git === null) {
                $this->failure($release, $reason, $diagnostic);
            } else {
                $this->recordLocked($release, $actor, $git);
            }
        } catch (\Throwable $error) {
            $this->failure($release, $error instanceof RecordingFailure ? $error->reason : 'completion',
                $error instanceof RecordingFailure ? $error->diagnostic() : null);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Retry never remirrors, republishes, changes a pin or reads the real index. */
    public function retry(BeamUxRelease $release, Authenticatable $actor): void
    {
        $this->authorize($release, $actor);
        $receipt = $this->receipt($release);
        if ($receipt === null || $receipt->state === 'unavailable' || $receipt->manifest === []) {
            throw new RecordingFailure('unavailable', 'This Release has no supported frozen recording receipt.');
        }
        $disk = $this->disk();
        foreach ($receipt->manifest as $file) {
            $this->contained($disk->path(''), $file['path']);
            if ($disk->path($file['path']) !== $file['absolute']) {
                throw new RecordingFailure('path', 'The mirror disk no longer matches this frozen receipt.');
            }
        }
        $git = new LocalGit($disk->path(''));
        $lock = $git->lock();
        try {
            $this->recordLocked($release, $actor, $git);
        } catch (\Throwable $error) {
            $this->failure($release, $error instanceof RecordingFailure ? $error->reason : 'git-failed',
                $error instanceof RecordingFailure ? $error->diagnostic() : null);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function authorize(BeamUxRelease $release, Authenticatable $actor): void
    {
        app(ReleaseService::class)->authorizeRecording($release, $actor);
        $tenancy = app(TenancyContext::class);
        try {
            $active = $tenancy->isActive();
            $unknownSplit = $tenancy->isMultiTenant() && $tenancy->centralConnection() === null;
        } catch (\Throwable) {
            throw new RecordingFailure('tenancy', 'The tenancy context cannot be verified.');
        }
        if ($active || $unknownSplit) {
            throw new RecordingFailure('tenancy', 'Local recording is unavailable while tenancy is active.');
        }
        $probe = (new BeamUxEntry)->setConnection($release->getConnectionName());
        if (! app(EntryResidency::class)->isCentralResident($probe)) {
            throw new RecordingFailure('tenancy', 'The Release is not proven central-resident.');
        }
        foreach ($release->members()->get() as $member) {
            $entry = BeamUxEntry::on($release->getConnectionName())->find($member->entry_id);
            if ($entry === null || ! app(EntryResidency::class)->isCentralResident($entry)) {
                throw new RecordingFailure('tenancy', 'Every Release member must be proven central-resident.');
            }
        }
    }

    private function recordLocked(BeamUxRelease $release, Authenticatable $actor, LocalGit $git): void
    {
        $this->authorize($release, $actor);
        $row = $this->receipt($release);
        if ($row === null || $row->state === 'unavailable' || $row->manifest === []
            || count($row->manifest) !== $release->members()->count()
            || count(array_unique(array_column($row->manifest, 'entryId'))) !== count($row->manifest)) {
            throw new RecordingFailure('unavailable', 'This Release has no supported frozen recording receipt.');
        }
        $git->assertSupported();
        $identity = ['root' => $git->root, 'directory' => $git->directory, 'common' => $git->common];
        if ($row->getRawOriginal('repository') !== null && ($row->repository !== $identity || $row->branch !== $git->branch)) {
            throw new RecordingFailure('recovery-required', 'The saved recording belongs to a different repository or branch.');
        }
        $manifest = [];
        foreach ($row->manifest as $file) {
            $entry = BeamUxEntry::on($release->getConnectionName())->findOrFail($file['entryId']);
            $member = $release->members()->where('entry_id', $entry->id)->first();
            $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
            $deleting = ($file['action'] ?? null) === 'delete';
            if ($member === null || ($deleting ? $member->version_id !== null : $member->version_id !== $file['versionId'])
                || $this->disk()->path($path) !== $file['absolute']) {
                throw new RecordingFailure('manifest', 'Release membership or mirror placement no longer matches its frozen receipt.');
            }
            $file['path'] = $git->relative($file['absolute']);
            if ($deleting) {
                $old = base64_decode($file['bytes'] ?? '', true);
                if ($old === false || hash('sha256', $old) !== $file['sha256']
                    || ! $git->baseFileMatches($row->base ?? $git->head(), $file['path'], $file['sha256'], $file['mode'])) {
                    throw new RecordingFailure('mirror-drift', 'The Git worktree file differs from the published Take-down bytes.');
                }
            }
            $manifest[] = $file;
        }
        if ($row->sha !== null) {
            if ($row->no_change) {
                if ($git->baseTree($row->sha) !== $row->tree || ! $git->reachable($row->sha)) {
                    throw new RecordingFailure('recovery-required', 'The saved no-change Git result is no longer reachable.');
                }
            } else {
                $git->verify($row->sha, $row->base, $row->tree, $manifest, $row->id);
                if (! $git->reachable($row->sha)) {
                    throw new RecordingFailure('recovery-required', 'The recorded Git result is no longer reachable.');
                }
            }

            return;
        }
        // A saved candidate is recovered before current mirror checks: later publication/manual edits
        // cannot cause an already installed commit to be duplicated or misreported as a new attempt.
        if ($row->candidate !== null) {
            $this->recover($row, $git, $manifest);

            return;
        }
        foreach ($manifest as $file) {
            $path = $git->path($file['path']);
            if (($file['action'] ?? null) === 'delete') {
                if (file_exists($path)) {
                    throw new RecordingFailure('mirror-drift', 'A Take-down path reappeared after its frozen deletion.');
                }
                if ($row->state === 'failed' && ! str_starts_with((string) $row->error, 'mirror-deleted:')) {
                    throw new RecordingFailure('mirror-drift', 'An absent Take-down path is not proven to have been removed by this Release.');
                }

                continue;
            }
            if (! is_file($path) || hash_file('sha256', $path) !== $file['sha256']
                || (is_executable($path) ? '100755' : '100644') !== $file['mode']) {
                throw new RecordingFailure('mirror-drift', 'A file differs from this Release. Resolve the file explicitly before recording.');
            }
        }
        if ($row->preparation !== null) {
            if (! is_file($row->preparation.'/HEAD')
                || trim((string) file_get_contents($row->preparation.'/HEAD')) !== $row->base) {
                throw new RecordingFailure('recovery-required', 'An unsaved or missing Git preparation needs explicit recovery; no second commit was attempted.');
            }
        }
        // With no saved/orphan candidate, a failed hook created no commit. A new serialized
        // attempt may capture the current parent while retaining the same frozen Release identity.
        // A saved candidate takes the recovery-only branch above and never gets a new parent.
        $base = $git->head();
        $work = storage_path('framework/beam-ux-recordings/'.$row->id.'/attempt-'.($row->attempt + 1));
        $deletionApplied = collect($manifest)->contains(fn (array $file) => ($file['action'] ?? null) === 'delete');
        $row->forceFill(['repository' => $identity, 'branch' => $git->branch, 'base' => $base,
            'preparation' => $work, 'state' => $deletionApplied ? 'mirror-deleted' : 'pending', 'error' => null,
            'attempt' => $row->attempt + 1])->save();
        $tree = $git->tree($work, $base, $manifest);
        $row->forceFill(['tree' => $tree])->save();
        if ($tree === $git->baseTree($base)) {
            $row->forceFill(['state' => 'recorded', 'sha' => $base, 'no_change' => true])->save();

            return;
        }
        $sha = $git->commit($work, $base, $release->title."\n\nBeam-UX-Release: ".$release->id."\nBeam-UX-Recording: ".$row->id);
        $git->verify($sha, $base, $tree, $manifest, $row->id);
        $git->anchor($row->id, $sha);
        $row->forceFill(['candidate' => $sha])->save(); // Failure here cannot advance source HEAD.
        $this->recover($row, $git, $manifest);
    }

    private function recover(BeamUxReleaseRecording $row, LocalGit $git, array $manifest): void
    {
        $git->verify($row->candidate, $row->base, $row->tree, $manifest, $row->id);
        if (! $git->reachable($row->candidate)) {
            if ($row->installation_started) {
                throw new RecordingFailure('recovery-required', 'Installation started but the saved candidate is not reachable. No second installation was attempted.');
            }
            if ($git->head() !== $row->base) {
                throw new RecordingFailure('recovery-required', 'The branch moved without the saved recording. No second commit was attempted.');
            }
            $row->forceFill(['installation_started' => true])->save();
            $git->install($row->candidate, $row->base);
        }
        $this->persistResult($row, $row->candidate);
    }

    /** Separate seam for a real result-write failure test after successful branch CAS. */
    protected function persistResult(BeamUxReleaseRecording $row, string $sha): void
    {
        $row->forceFill(['state' => 'recorded', 'sha' => $sha, 'error' => null])->save();
    }

    private function failure(BeamUxRelease $release, string $reason, ?string $diagnostic = null): void
    {
        try {
            $row = $this->receipt($release);
            if ($row !== null) {
                if ($row->state === 'unavailable') {
                    return;
                }
                $afterDelete = $row->state === 'mirror-deleted';
                $row->forceFill(['state' => $row->candidate !== null || $reason === 'recovery-required' ? 'recovery-required' : 'failed',
                    'error' => ($afterDelete ? 'mirror-deleted:' : '').($diagnostic === null ? $reason : $reason.':'.$diagnostic)])->save();
            }
        } catch (\Throwable) {
            // Durable pending identity/candidate remains available when the receipt store recovers.
        }
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $name = config('beam.ux.storage.mirror_disk');
        if (! is_string($name) || config('filesystems.disks.'.$name.'.driver') !== 'local') {
            throw new RecordingFailure('mirror-disabled', 'Local recording needs an enabled local mirror disk.');
        }

        return Storage::disk($name);
    }

    /** Configured root aliases are supported; user-resolved child paths must remain inside it. */
    private function mirrorContained(string $root, string $path): void
    {
        clearstatcache(true);
        $parts = $this->relativeParts($path);
        $cursor = rtrim($root, '/');
        if (! str_starts_with($cursor, '/')) {
            throw new RecordingFailure('path', 'The configured mirror root must be absolute.');
        }
        $missing = [];
        while (($canonical = realpath($cursor)) === false) {
            if (is_link($cursor) || $cursor === dirname($cursor)) {
                throw new RecordingFailure('path', 'The configured mirror root cannot be resolved.');
            }
            $part = basename($cursor);
            if ($part === '.' || $part === '..') {
                throw new RecordingFailure('path', 'The configured mirror root cannot be resolved.');
            }
            $missing[] = $part;
            $cursor = dirname($cursor);
        }
        $canonical = rtrim($canonical, '/');
        foreach (array_reverse($missing) as $part) {
            $canonical .= '/'.$part;
        }
        $cursor = rtrim($root, '/');
        foreach ($parts as $part) {
            $cursor .= '/'.$part;
            $resolved = realpath($cursor);
            if ((is_link($cursor) && $resolved === false)
                || ($resolved !== false && $resolved !== $canonical && ! str_starts_with($resolved, $canonical.'/'))) {
                throw new RecordingFailure('path', 'A resolved mirror path leaves its configured root.');
            }
        }
    }

    private function contained(string $root, string $path): void
    {
        $cursor = '';
        foreach (explode('/', trim($root, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RecordingFailure('path', 'The mirror root must be a canonical local directory.');
            }
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RecordingFailure('path', 'Symlink mirror roots cannot be recorded.');
            }
        }
        foreach ($this->relativeParts($path) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RecordingFailure('path', 'Symlink mirror paths cannot be recorded.');
            }
            if (is_dir($cursor) && file_exists($cursor.'/.git')) {
                throw new RecordingFailure('path', 'Nested Git worktrees cannot be mirror members.');
            }
        }
    }

    private function relativeParts(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new RecordingFailure('path', 'The mirror path is not a contained regular file.');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new RecordingFailure('path', 'The mirror path is not a contained regular file.');
            }
        }

        return $parts;
    }
}
