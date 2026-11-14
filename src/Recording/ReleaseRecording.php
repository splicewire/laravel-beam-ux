<?php

namespace Splicewire\Beam\Ux\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
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

    /**
     * Called inside publication: freezes the receipt (an optional projection failure contained in its own savepoint) and
     * returns the completion — mirror, Git, artifact retention — for the caller to run once the publication commits.
     * {@see GitRecordingSink} hands it to the publish sinks registry, which runs it after commit and contains its failure.
     */
    public function prepare(BeamUxRelease $release, array $prepared, Authenticatable $actor, array $takedowns = []): \Closure
    {
        $manifest = [];
        $failure = null;
        try {
            $disk = $this->disk();
            $seen = [];
            $size = 0;
            foreach ($prepared as $member) {
                [$entry, $version, $body] = $member;
                $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
                $bytes = app(PlacedDiskMirror::class)->bytes($entry, $body);
                $absolute = $disk->path($path);
                $this->contained($disk->path(''), $path);
                $size += strlen($bytes);
                if ($size > 4194304 || isset($seen[strtolower($absolute)])) {
                    throw new RecordingFailure('manifest', RecordingError::ManifestReleaseMirrorManifestTooLargeOr);
                }
                $seen[strtolower($absolute)] = true;
                $manifest[] = [...self::entryRow($entry, $disk), 'entryId' => (string) $entry->id, 'versionId' => (string) $version->id,
                    'codec' => $entry->codec()::class, 'path' => $path, 'absolute' => $absolute,
                    'mode' => is_file($absolute) && is_executable($absolute) ? '100755' : '100644',
                    'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
                // ADR-0233 d5: a File Language change moved this member's file. The old path is a frozen deletion in
                // the same commit, of exactly the bytes published there, so an edited old file refuses like a Take-down.
                if (($moved = $member['moved'] ?? null) !== null) {
                    $old = $disk->path($moved['path']);
                    $this->contained($disk->path(''), $moved['path']);
                    $size += strlen($moved['bytes']);
                    if ($size > 4194304 || isset($seen[strtolower($old)]) || ! is_file($old)
                        || hash_file('sha256', $old) !== hash('sha256', $moved['bytes'])) {
                        throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftMovedPagesOldMirrorDiffersFrom);
                    }
                    $seen[strtolower($old)] = true;
                    $manifest[] = [...self::entryRow($entry, $disk), 'action' => 'delete', 'move' => true, 'entryId' => (string) $entry->id, 'versionId' => (string) $version->id,
                        'codec' => $moved['codec'], 'path' => $moved['path'], 'absolute' => $old,
                        'mode' => is_executable($old) ? '100755' : '100644',
                        'bytes' => base64_encode($moved['bytes']), 'sha256' => hash('sha256', $moved['bytes'])];
                }
            }
            foreach ($takedowns as [$entry, $version, $body]) {
                $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
                $bytes = app(PlacedDiskMirror::class)->bytes($entry, $body);
                $absolute = $disk->path($path);
                $this->contained($disk->path(''), $path);
                $size += strlen($bytes);
                if ($size > 4194304 || isset($seen[strtolower($absolute)]) || ! is_file($absolute)
                    || hash_file('sha256', $absolute) !== hash('sha256', $bytes)) {
                    throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftTakeDownMirrorDiffersFromCurrently);
                }
                $seen[strtolower($absolute)] = true;
                $manifest[] = [...self::entryRow($entry, $disk), 'action' => 'delete', 'entryId' => (string) $entry->id, 'versionId' => (string) $version->id,
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

        return function () use ($snapshot, $prepared, $takedowns, $actor): void {
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
        };
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
                $rows = $receipt === null ? [] : $this->rows($receipt->manifest);
                if ($receipt === null || $receipt->state === 'unavailable' || count(self::memberFiles($rows)) !== $release->members()->count()) {
                    throw new RecordingFailure('unavailable', RecordingError::UnavailableNoDurableRecordingReceiptAvailable);
                }
                $git = new LocalGit($this->disk()->path(''));
                $lock = $git->lock();
                foreach ($rows as $file) {
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
                            throw new RecordingFailure('superseded', RecordingError::SupersededNewerPublicationSupersededMirrorCompletion);
                        }
                    } elseif ($current === null || $current->published_version !== (string) $version->id
                        || $current->workflow_marking === BeamUxEntry::MARKING_PUBLISHED) {
                        throw new RecordingFailure('superseded', RecordingError::SupersededNewerPublicationSupersededTakeDownCompletion);
                    }
                }
                $diskName = config('beam.ux.storage.mirror_disk');
                $disk = is_string($diskName) && $diskName !== '' ? Storage::disk($diskName) : null;
                if ($disk === null) {
                    throw new RecordingFailure('mirror-disabled', RecordingError::MirrorDisabledNoMirrorDiskEnabled);
                }
                $local = config('filesystems.disks.'.$diskName.'.driver') === 'local';
                if ($takedowns !== [] && ! $local) {
                    throw new RecordingFailure('mirror-disabled', RecordingError::MirrorDisabledTakeDownRecordingNeedsEnabledLocal);
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
                        throw new RecordingFailure('mirror', RecordingError::MirrorReleaseMirrorCouldNotCompleted);
                    }
                    $actual = $disk->get($path);
                    if ($actual !== app(PlacedDiskMirror::class)->bytes($entry, $body)) {
                        throw new RecordingFailure('mirror', RecordingError::MirrorReleaseMirrorDidNotMatchPublished);
                    }
                }
                $this->completeMoves($release, $disk, $local, $preparedIds, $allMembers);
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
                            throw new RecordingFailure('manifest', RecordingError::ManifestTakeDownMissingFrozenMirrorReceipt);
                        }
                        $path = $file['path'];
                        $this->mirrorContained($disk->path(''), $path);
                        if (! is_file($disk->path($path)) || hash_file('sha256', $disk->path($path)) !== $file['sha256']
                            || (is_executable($disk->path($path)) ? '100755' : '100644') !== $file['mode']) {
                            throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftTakeDownMirrorDiffersFromFrozen);
                        }
                        if (! $disk->delete($path) || $disk->exists($path)) {
                            throw new RecordingFailure('mirror', RecordingError::MirrorTakeDownMirrorCouldNotRemoved);
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

    /**
     * ADR-0233 d5 — remove the files a File Language change moved members away from. A move the receipt froze is
     * drift-checked against its frozen bytes and deleted like a Take-down, so the one commit holds both paths; without a
     * frozen move (no receipt, or recording unavailable) the mirror still removes the old file only while it is
     * unchanged.
     *
     * @param  array<string, true>  $preparedIds
     * @param  list<array<int, mixed>>  $allMembers
     */
    private function completeMoves(BeamUxRelease $release, \Illuminate\Contracts\Filesystem\Filesystem $disk, bool $local, array $preparedIds, array $allMembers): void
    {
        $receipt = null;
        $deleted = false;
        foreach ($allMembers as $member) {
            $moved = $member['moved'] ?? null;
            if ($moved === null || ! isset($preparedIds[(string) $member[0]->getKey()])) {
                continue;
            }
            $receipt ??= $this->receipt($release);
            $file = $receipt === null || $receipt->state === 'unavailable' || ! $local ? null
                : collect($receipt->manifest)->first(fn (array $item) => ($item['move'] ?? false) === true && $item['entryId'] === (string) $member[0]->id);
            if ($file === null) {
                app(PlacedDiskMirror::class)->removeIfUnchanged($moved['path'], $moved['bytes']);

                continue;
            }
            $this->mirrorContained($disk->path(''), $file['path']);
            if (! is_file($disk->path($file['path'])) || hash_file('sha256', $disk->path($file['path'])) !== $file['sha256']
                || (is_executable($disk->path($file['path'])) ? '100755' : '100644') !== $file['mode']) {
                throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftMovedPagesOldMirrorDiffersFrom2);
            }
            if (! app(PlacedDiskMirror::class)->remove($file['path']) || $disk->exists($file['path'])) {
                throw new RecordingFailure('mirror', RecordingError::MirrorMovedPagesOldMirrorCouldNot);
            }
            $deleted = true;
        }
        if ($deleted) {
            $receipt?->forceFill(['state' => 'mirror-deleted'])->save();
        }
    }

    /**
     * What every manifest row says of itself (ADR-0243): the member's kind, its ref, and the root its `path` is
     * relative to. An entry row's root is the mirror disk's.
     *
     * @return array{kind: string, ref: string, root: string}
     */
    private static function entryRow(BeamUxEntry $entry, \Illuminate\Contracts\Filesystem\Filesystem $disk): array
    {
        return ['kind' => BeamUxReleaseMember::KIND_ENTRY, 'ref' => (string) $entry->id, 'root' => $disk->path('')];
    }

    /**
     * A receipt's rows, each with its kind, ref and root. A receipt frozen before rows carried them holds entry rows
     * only, under the mirror disk, so a row without them reads as `entry`, its ref its `entryId`, its root the disk's:
     * exactly what completion and retry assumed of it before. Local recording verifies entry rows under the mirror disk;
     * a row of another kind, or under another root, refuses rather than being read as an entry.
     *
     * @param  list<array<string, mixed>>  $manifest
     * @return list<array<string, mixed>>
     */
    private function rows(array $manifest): array
    {
        $root = $this->disk()->path('');

        return array_map(function (array $file) use ($root): array {
            $file += ['kind' => BeamUxReleaseMember::KIND_ENTRY, 'ref' => (string) ($file['entryId'] ?? ''), 'root' => $root];
            if ($file['kind'] !== BeamUxReleaseMember::KIND_ENTRY) {
                throw new RecordingFailure('unavailable', RecordingError::UnavailableReleaseHasNoSupportedFrozenRecording);
            }
            if ($file['root'] !== $root) {
                throw new RecordingFailure('path', RecordingError::PathMirrorDiskNoLongerMatchesFrozen);
            }

            return $file;
        }, $manifest);
    }

    /**
     * The manifest's one file per member: a File Language move adds its old path's deletion beside it.
     *
     * @param  list<array<string, mixed>>  $manifest
     * @return list<array<string, mixed>>
     */
    private static function memberFiles(array $manifest): array
    {
        return array_values(array_filter($manifest, fn (array $file) => ($file['move'] ?? false) !== true));
    }

    /** Retry never remirrors, republishes, changes a pin or reads the real index. */
    public function retry(BeamUxRelease $release, Authenticatable $actor): void
    {
        $this->authorize($release, $actor);
        $receipt = $this->receipt($release);
        if ($receipt === null || $receipt->state === 'unavailable' || $receipt->manifest === []) {
            throw new RecordingFailure('unavailable', RecordingError::UnavailableReleaseHasNoSupportedFrozenRecording);
        }
        $disk = $this->disk();
        foreach ($this->rows($receipt->manifest) as $file) {
            $this->contained($disk->path(''), $file['path']);
            if ($disk->path($file['path']) !== $file['absolute']) {
                throw new RecordingFailure('path', RecordingError::PathMirrorDiskNoLongerMatchesFrozen);
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
            throw new RecordingFailure('tenancy', RecordingError::TenancyTenancyContextCannotVerified);
        }
        if ($active || $unknownSplit) {
            throw new RecordingFailure('tenancy', RecordingError::TenancyLocalRecordingUnavailableWhileTenancyActive);
        }
        $probe = (new BeamUxEntry)->setConnection($release->getConnectionName());
        if (! app(EntryResidency::class)->isCentralResident($probe)) {
            throw new RecordingFailure('tenancy', RecordingError::TenancyReleaseNotProvenCentralResident);
        }
        foreach ($release->members()->get() as $member) {
            $entry = BeamUxEntry::on($release->getConnectionName())->find($member->entry_id);
            if ($entry === null || ! app(EntryResidency::class)->isCentralResident($entry)) {
                throw new RecordingFailure('tenancy', RecordingError::TenancyEveryReleaseMemberMustProvenCentral);
            }
        }
    }

    private function recordLocked(BeamUxRelease $release, Authenticatable $actor, LocalGit $git): void
    {
        $this->authorize($release, $actor);
        $row = $this->receipt($release);
        $rows = $row === null ? [] : $this->rows($row->manifest);
        $members = self::memberFiles($rows);
        $moves = array_values(array_filter($rows, fn (array $file) => ($file['move'] ?? false) === true));
        if ($row === null || $row->state === 'unavailable' || $rows === []
            || count($members) !== $release->members()->count()
            || count(array_unique(array_column($members, 'entryId'))) !== count($members)
            || count(array_unique(array_column($moves, 'entryId'))) !== count($moves)
            || array_diff(array_column($moves, 'entryId'), array_column($members, 'entryId')) !== []) {
            throw new RecordingFailure('unavailable', RecordingError::UnavailableReleaseHasNoSupportedFrozenRecording);
        }
        $git->assertSupported();
        $identity = ['root' => $git->root, 'directory' => $git->directory, 'common' => $git->common];
        if ($row->getRawOriginal('repository') !== null && ($row->repository !== $identity || $row->branch !== $git->branch)) {
            throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredSavedRecordingBelongsDifferentRepositoryOr);
        }
        $manifest = [];
        foreach ($rows as $file) {
            $entry = BeamUxEntry::on($release->getConnectionName())->findOrFail($file['entryId']);
            $member = $release->members()->where('entry_id', $entry->id)->first();
            $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
            $deleting = ($file['action'] ?? null) === 'delete';
            // A move's deletion belongs to a publishing member whose placement has left the frozen old path.
            $moving = $deleting && ($file['move'] ?? false) === true;
            $placed = $this->disk()->path($path) === $file['absolute'];
            if ($member === null || ($moving
                ? $member->version_id !== $file['versionId'] || $placed
                : ($deleting ? $member->version_id !== null : $member->version_id !== $file['versionId']) || ! $placed)) {
                throw new RecordingFailure('manifest', RecordingError::ManifestReleaseMembershipOrMirrorPlacementNo);
            }
            $file['path'] = $git->relative($file['absolute']);
            if ($deleting) {
                $old = base64_decode($file['bytes'] ?? '', true);
                if ($old === false || hash('sha256', $old) !== $file['sha256']
                    || ! $git->baseFileMatches($row->base ?? $git->head(), $file['path'], $file['sha256'], $file['mode'])) {
                    throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftGitWorktreeFileDiffersFromPublished);
                }
            }
            $manifest[] = $file;
        }
        if ($row->sha !== null) {
            if ($row->no_change) {
                if ($git->baseTree($row->sha) !== $row->tree || ! $git->reachable($row->sha)) {
                    throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredSavedNoChangeGitResultNo);
                }
            } else {
                $git->verify($row->sha, $row->base, $row->tree, $manifest, $row->id);
                if (! $git->reachable($row->sha)) {
                    throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredRecordedGitResultNoLongerReachable);
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
                    throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftTakeDownPathReappearedAfterFrozen);
                }
                if ($row->state === 'failed' && ! str_starts_with((string) $row->error, 'mirror-deleted:')) {
                    throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftAbsentTakeDownPathNotProven);
                }

                continue;
            }
            if (! is_file($path) || hash_file('sha256', $path) !== $file['sha256']
                || (is_executable($path) ? '100755' : '100644') !== $file['mode']) {
                throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftFileDiffersFromReleaseResolveFile);
            }
        }
        if ($row->preparation !== null) {
            if (! is_file($row->preparation.'/HEAD')
                || trim((string) file_get_contents($row->preparation.'/HEAD')) !== $row->base) {
                throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredUnsavedOrMissingGitPreparationNeeds);
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
                throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredInstallationStartedButSavedCandidateNot);
            }
            if ($git->head() !== $row->base) {
                throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredBranchMovedWithoutSavedRecordingNo);
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
            throw new RecordingFailure('mirror-disabled', RecordingError::MirrorDisabledLocalRecordingNeedsEnabledLocalMirror);
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
            throw new RecordingFailure('path', RecordingError::PathConfiguredMirrorRootMustAbsolute);
        }
        $missing = [];
        while (($canonical = realpath($cursor)) === false) {
            if (is_link($cursor) || $cursor === dirname($cursor)) {
                throw new RecordingFailure('path', RecordingError::PathConfiguredMirrorRootCannotResolved);
            }
            $part = basename($cursor);
            if ($part === '.' || $part === '..') {
                throw new RecordingFailure('path', RecordingError::PathConfiguredMirrorRootCannotResolved);
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
                throw new RecordingFailure('path', RecordingError::PathResolvedMirrorPathLeavesConfiguredRoot);
            }
        }
    }

    private function contained(string $root, string $path): void
    {
        $cursor = '';
        foreach (explode('/', trim($root, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RecordingFailure('path', RecordingError::PathMirrorRootMustCanonicalLocalDirectory);
            }
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RecordingFailure('path', RecordingError::PathSymlinkMirrorRootsCannotRecorded);
            }
        }
        foreach ($this->relativeParts($path) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                throw new RecordingFailure('path', RecordingError::PathSymlinkMirrorPathsCannotRecorded);
            }
            if (is_dir($cursor) && file_exists($cursor.'/.git')) {
                throw new RecordingFailure('path', RecordingError::PathNestedGitWorktreesCannotMirrorMembers);
            }
        }
    }

    private function relativeParts(string $path): array
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new RecordingFailure('path', RecordingError::PathMirrorPathNotContainedRegularFile);
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new RecordingFailure('path', RecordingError::PathMirrorPathNotContainedRegularFile);
            }
        }

        return $parts;
    }
}
