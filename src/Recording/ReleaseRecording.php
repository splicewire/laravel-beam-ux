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
        // config-sections 08 AC4: a tenant-resident Release makes no mirror file and no commit, so recording does not
        // apply to it. It freezes no receipt and runs no completion: completing it could only fail (the central mirror
        // refuses a tenant entry), which once stored a `failed` receipt for a Release that was never meant to record.
        if (! $this->applies($release)) {
            return function () use ($prepared, $takedowns): void {
                $this->prune(array_merge($prepared, $takedowns));
            };
        }
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
        $this->freeze($release, $manifest, $failure);
        $snapshot = clone $release;

        return function () use ($snapshot, $prepared, $takedowns, $actor): void {
            try {
                $this->complete($snapshot->fresh(), $prepared, $takedowns, $actor);
            } catch (\Throwable) {
                // A post-commit optional side effect cannot become a failed-publication HTTP result.
                $this->failure($snapshot, 'completion');
            }
            $this->prune(array_merge($prepared, $takedowns));
        };
    }

    /**
     * Whether local recording applies to this Release at all: only a central-resident Release records. A tenant's
     * Release publishes to its own store and never reaches the central mirror or its Git (config-sections 08 AC4), so
     * the recording reports `not-applicable` for it rather than a failure. It is decided by the RELEASE'S OWN residency,
     * never by the ambient tenancy: only a positive answer that the Release is tenant-resident makes recording not
     * apply ({@see tenantResident()}). A central-pinned Release under an active tenant context, or wherever residency
     * cannot be answered, takes the recording path and refuses there visibly with reason `tenancy` ({@see authorize()}).
     */
    public function applies(BeamUxRelease $release): bool
    {
        return ! $this->tenantResident($release);
    }

    /**
     * A POSITIVE answer that the Release lives in a tenant's store: the tenancy context answers, a central connection is
     * configured, and the {@see EntryResidency} probe on the Release's connection says it is not central. A context
     * that throws, or a split with no central connection named, is no answer (false), so it never reads as
     * not-applicable; {@see authorize()} refuses it instead.
     */
    private function tenantResident(BeamUxRelease $release): bool
    {
        try {
            $tenancy = app(TenancyContext::class);
            $tenancy->isActive();
            if ($tenancy->centralConnection() === null) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return ! app(EntryResidency::class)->isCentralResident((new BeamUxEntry)->setConnection($release->getConnectionName()));
    }

    /** @param  list<array<int, mixed>>  $members */
    private function prune(array $members): void
    {
        foreach ($members as [$entry]) {
            try {
                app(\Splicewire\Beam\Ux\Compile\CompileEntryBody::class)->artifacts()->prune($entry->fresh());
            } catch (\Throwable) {
                // Retention failure is independent of publication/mirroring/recording.
            }
        }
    }

    /**
     * Freeze a Release's config-override members into the SAME receipt as its entry members (config-sections 08; ADR-0243
     * d3 per-kind projection, d4 the config root). Each root's PUBLISHED mirror bytes become one `config/overrides/<root>.json`
     * row added to the per-release receipt. The config files ride the ENTRY closure's single commit when the Release has
     * entry members (decided from release state, not registration order); only a config-ONLY Release records from here.
     *
     * @param  list<string>  $roots
     */
    public function prepareConfig(BeamUxRelease $release, array $roots, Authenticatable $actor): \Closure
    {
        // A tenant-resident Release: recording does not apply ({@see prepare()}), so no receipt and no completion.
        if (! $this->applies($release)) {
            return function (): void {};
        }
        $manifest = [];
        $failure = null;
        try {
            $disk = $this->disk();
            $mirror = app(ConfigOverrideMirror::class);
            // A tenant Release records nothing to the central mirror, so it freezes no config rows either; completion
            // guards the write as well ({@see complete()}).
            foreach ($this->writesConfigMirror($release) ? $roots : [] as $root) {
                $bytes = $mirror->bytes($root, $release->getConnectionName());
                $path = ConfigOverrideMirror::pathFor($root);
                if ($bytes === null) {
                    // The override now has NO published layer (a rollback reverted the pin to null). If a committed mirror
                    // file exists, record its DELETE so the one commit drops it — otherwise a later import would
                    // re-introduce an override the DB no longer has (config-sections 08 import AC). No committed file: no row.
                    if (! $disk->exists($path)) {
                        continue;
                    }
                    $this->contained($disk->path(''), $path);
                    $existing = (string) $disk->get($path);
                    $manifest[] = ['kind' => BeamUxReleaseMember::KIND_CONFIG, 'ref' => $root, 'root' => ConfigOverrideMirror::ROOT,
                        'action' => 'delete', 'path' => $path, 'absolute' => $disk->path($path),
                        'mode' => '100644', 'bytes' => base64_encode($existing), 'sha256' => hash('sha256', $existing)];

                    continue;
                }
                $this->contained($disk->path(''), $path);
                $manifest[] = ['kind' => BeamUxReleaseMember::KIND_CONFIG, 'ref' => $root, 'root' => ConfigOverrideMirror::ROOT,
                    'path' => $path, 'absolute' => $disk->path($path),
                    'mode' => '100644', 'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
            }
        } catch (\Throwable $error) {
            $failure = $error instanceof RecordingFailure ? $error->reason : 'preparation';
        }
        $this->freeze($release, $manifest, $failure);
        $snapshot = clone $release;

        return function () use ($snapshot, $actor): void {
            // When the Release has entry members, the entry closure's completion records every kind's rows in one commit;
            // this config closure defers to it. A config-ONLY Release is recorded here.
            if ($snapshot->members()->where('member_kind', BeamUxReleaseMember::KIND_ENTRY)->exists()) {
                return;
            }
            try {
                $this->complete($snapshot->fresh(), [], [], $actor);
            } catch (\Throwable) {
                $this->failure($snapshot, 'completion');
            }
        };
    }

    /**
     * Write or extend the per-release receipt: a kind ADDS its frozen rows to the one receipt row (config-sections 08),
     * keyed by path, so each kind projecting its own members (ADR-0243 d3) lands in one receipt and one commit. A freeze
     * failure marks the receipt unavailable, and once unavailable it stays so.
     *
     * @param  list<array<string, mixed>>  $manifest
     */
    private function freeze(BeamUxRelease $release, array $manifest, ?string $failure): void
    {
        try {
            $release->getConnection()->transaction(function () use ($release, $manifest, $failure) {
                $existing = BeamUxReleaseRecording::on($release->getConnectionName())
                    ->where('release_id', $release->id)->lockForUpdate()->first();
                $merged = $this->mergeManifest($existing?->manifest ?? [], $manifest);
                $row = $existing ?? (new BeamUxReleaseRecording)->setConnection($release->getConnectionName());
                $state = ($failure !== null || ($existing && $existing->state === 'unavailable')) ? 'unavailable' : 'pending';
                $row->forceFill(['release_id' => $release->id, 'manifest' => $merged,
                    'state' => $state, 'error' => $failure ?? ($existing->error ?? null)])->save();
            });
        } catch (\Throwable) {
            // Without a durable receipt, completion may mirror but MUST NOT run Git.
        }
    }

    /**
     * Merge a kind's freshly frozen rows into the receipt, keyed by path: a kind re-freezing replaces its own rows, a new
     * kind appends, others stay. One receipt row, one commit.
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $added
     * @return list<array<string, mixed>>
     */
    private function mergeManifest(array $existing, array $added): array
    {
        $addedPaths = array_fill_keys(array_map(fn (array $r): string => (string) ($r['path'] ?? ''), $added), true);
        $kept = array_values(array_filter($existing, fn (array $r): bool => ! isset($addedPaths[(string) ($r['path'] ?? '')])));

        return array_merge($kept, $added);
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
                if ($receipt === null || $receipt->state === 'unavailable' || count(self::memberFiles($rows)) !== $release->allMembers()->count()) {
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
                // config-sections 08 (ADR-0243 d4): write the CONFIG-override mirror files from the frozen receipt, so a
                // config override rides the entry closure's single commit. Config rows only — entry bodies are already
                // mirrored by EntryPublication; this leaves the entry mirror/move/takedown path untouched. Only a CENTRAL
                // Release writes them: the mirror disk is the central site's tree, so a tenant's config never reaches it,
                // whatever the receipt froze (the rule PlacedDiskMirror applies to a tenant-resident entry, ADR-0233 d6).
                if ($local && $this->centralResident($release)) {
                    foreach (($this->receipt($release)?->manifest ?? []) as $file) {
                        if (($file['kind'] ?? null) !== BeamUxReleaseMember::KIND_CONFIG) {
                            continue;
                        }
                        $path = (string) ($file['path'] ?? '');
                        $this->mirrorContained($disk->path(''), $path);
                        $bytes = base64_decode((string) ($file['bytes'] ?? ''), true);
                        if ($bytes === false) {
                            throw new RecordingFailure('mirror', RecordingError::MirrorReleaseMirrorCouldNotCompleted);
                        }
                        if (($file['action'] ?? null) === 'delete') {
                            // A rollback-to-null drops the committed override file (its frozen bytes drift-checked first), so
                            // the one commit removes it and a later import cannot re-introduce the override.
                            if (! $disk->exists($path) || hash('sha256', (string) $disk->get($path)) !== $file['sha256']) {
                                throw new RecordingFailure('mirror-drift', RecordingError::MirrorDriftTakeDownMirrorDiffersFromFrozen);
                            }
                            if (! $disk->delete($path) || $disk->exists($path)) {
                                throw new RecordingFailure('mirror', RecordingError::MirrorTakeDownMirrorCouldNotRemoved);
                            }

                            continue;
                        }
                        if (! $disk->put($path, $bytes) || $disk->get($path) !== $bytes) {
                            throw new RecordingFailure('mirror', RecordingError::MirrorReleaseMirrorCouldNotCompleted);
                        }
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
            if ($file['kind'] === BeamUxReleaseMember::KIND_ENTRY) {
                if ($file['root'] !== $root) {
                    throw new RecordingFailure('path', RecordingError::PathMirrorDiskNoLongerMatchesFrozen);
                }
            } elseif ($file['kind'] === BeamUxReleaseMember::KIND_CONFIG) {
                // config-sections 08 (ADR-0243 d4): a config override records under config/overrides/ on the mirror disk;
                // this kind now defines its rows, so it no longer refuses — a different kind or root still does.
                if ($file['root'] !== ConfigOverrideMirror::ROOT || ! str_starts_with((string) ($file['path'] ?? ''), ConfigOverrideMirror::ROOT)) {
                    throw new RecordingFailure('path', RecordingError::PathMirrorDiskNoLongerMatchesFrozen);
                }
            } else {
                throw new RecordingFailure('unavailable', RecordingError::UnavailableReleaseHasNoSupportedFrozenRecording);
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

    /**
     * Whether publishing this Release writes its config overrides' mirror files into the one commit
     * (config-sections ticket 11): a configured LOCAL mirror disk ({@see mirrorDiskName()}, the same predicate {@see disk()}
     * uses) and a central-resident Release. {@see prepareConfig()} acts on it, and the Release panel previews a file only
     * when it holds.
     */
    public function writesConfigMirror(BeamUxRelease $release): bool
    {
        return $this->mirrorDiskName() !== null && $this->centralResident($release);
    }

    /**
     * Whether the Release lives in the central realm: no tenant is active, and a probe on the Release's connection is
     * central-resident ({@see EntryResidency}). It fails closed: residency that cannot be determined is not central.
     */
    private function centralResident(BeamUxRelease $release): bool
    {
        try {
            if (app(TenancyContext::class)->isActive()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return app(EntryResidency::class)->isCentralResident((new BeamUxEntry)->setConnection($release->getConnectionName()));
    }

    private function recordLocked(BeamUxRelease $release, Authenticatable $actor, LocalGit $git): void
    {
        $this->authorize($release, $actor);
        $row = $this->receipt($release);
        $rows = $row === null ? [] : $this->rows($row->manifest);
        $members = self::memberFiles($rows);
        // config-sections 08: a receipt may hold both entry and config-override files. The per-entry uniqueness and
        // move-ownership invariants apply to ENTRY files (which carry an `entryId`); config files are counted toward the
        // total but validated by their own frozen bytes below, not by entry placement.
        $entryMembers = array_values(array_filter($members, fn (array $file) => ($file['kind'] ?? null) !== BeamUxReleaseMember::KIND_CONFIG));
        $moves = array_values(array_filter($rows, fn (array $file) => ($file['move'] ?? false) === true));
        if ($row === null || $row->state === 'unavailable' || $rows === []
            || count($members) !== $release->allMembers()->count()
            || count(array_unique(array_column($entryMembers, 'entryId'))) !== count($entryMembers)
            || count(array_unique(array_column($moves, 'entryId'))) !== count($moves)
            || array_diff(array_column($moves, 'entryId'), array_column($entryMembers, 'entryId')) !== []) {
            throw new RecordingFailure('unavailable', RecordingError::UnavailableReleaseHasNoSupportedFrozenRecording);
        }
        $git->assertSupported();
        $identity = ['root' => $git->root, 'directory' => $git->directory, 'common' => $git->common];
        if ($row->getRawOriginal('repository') !== null && ($row->repository !== $identity || $row->branch !== $git->branch)) {
            throw new RecordingFailure('recovery-required', RecordingError::RecoveryRequiredSavedRecordingBelongsDifferentRepositoryOr);
        }
        $manifest = [];
        foreach ($rows as $file) {
            if (($file['kind'] ?? null) === BeamUxReleaseMember::KIND_CONFIG) {
                // config-sections 08: a config-override file has no entry or placement. Its member is matched by section
                // ref; the frozen bytes are drift-checked by the common loop below — an added file must be present, a
                // delete (a rollback-to-null) must be absent (complete() removed it), exactly like an entry take-down.
                $member = $release->allMembers()->where('member_kind', BeamUxReleaseMember::KIND_CONFIG)
                    ->where('member_ref', (string) ($file['ref'] ?? ''))->first();
                if ($member === null) {
                    throw new RecordingFailure('manifest', RecordingError::ManifestReleaseMembershipOrMirrorPlacementNo);
                }
                $file['path'] = $git->relative($file['absolute']);
                $manifest[] = $file;

                continue;
            }
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
        $work = self::workRoot((string) $row->id).'/attempt-'.($row->attempt + 1);
        $deletionApplied = collect($manifest)->contains(fn (array $file) => ($file['action'] ?? null) === 'delete');
        $row->forceFill(['repository' => $identity, 'branch' => $git->branch, 'base' => $base,
            'preparation' => $work, 'state' => $deletionApplied ? 'mirror-deleted' : 'pending', 'error' => null,
            'attempt' => $row->attempt + 1])->save();
        $tree = $git->tree($work, $base, $manifest);
        $row->forceFill(['tree' => $tree])->save();
        if ($tree === $git->baseTree($base)) {
            $row->forceFill(['state' => 'recorded', 'sha' => $base, 'no_change' => true])->save();
            $this->discardWork($row);

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
        $this->refreshMirrorIndex($git, $row->candidate, (string) $row->base, $manifest);
        $this->discardWork($row);
    }

    /**
     * The commit was built in the attempt's private index, so the checkout's own index still describes the parent:
     * after installing, every recorded path read as a staged deletion (plus an untracked file), and a plain `git commit`
     * in the mirror would have committed the deletion of the Release's own record (config-sections 08). Refresh the
     * index for exactly the recorded paths, and only while the branch still points at the recorded commit; once it has
     * moved on, the index belongs to whoever moved it. A path whose index entry is neither the parent's nor the recorded
     * commit's holds content the operator staged; it is left exactly as it is (ADR-0226 d3) and reported by path, so
     * the operator sees their staged content against the new HEAD. A failure is reported, never thrown: the commit
     * exists and the recording stands.
     */
    private function refreshMirrorIndex(LocalGit $git, string $sha, string $base, array $manifest): void
    {
        try {
            if ($git->head() === $sha) {
                $kept = $git->refreshIndex($sha, $base, array_values(array_unique(array_column($manifest, 'path'))));
                if ($kept !== []) {
                    report(new \RuntimeException('Release recording '.$sha.' left the operator\'s own staged index entry as it was for: '
                        .implode(', ', $kept).'. Those paths now show the staged content against the recorded commit.'));
                }
            }
        } catch (\Throwable $failure) {
            report($failure);
        }
    }

    /** The per-recording directory under storage/framework that holds each attempt's private Git directory. */
    public static function workRoot(string $recordingId): string
    {
        return storage_path('framework/beam-ux-recordings/'.$recordingId);
    }

    /**
     * Remove a recorded receipt's attempt directories (config-sections 08 finding D). Each attempt directory is a
     * private GIT_DIR (HEAD, index, commondir): its objects live in the repository's common directory, and the
     * commit is anchored by `refs/beam-ux-recordings/<id>` and the branch. Once the result is saved, completion and
     * retry return on the saved sha before reading `preparation`, so nothing reads the directory again; left behind,
     * it is an untracked directory in the host. Only a `recorded` receipt is cleaned: a failed or recovery-required
     * attempt keeps its directory for recovery. A cleanup failure never affects the recording.
     */
    private function discardWork(BeamUxReleaseRecording $row): void
    {
        if ($row->state !== 'recorded' || $row->sha === null || ! preg_match('/^[A-Za-z0-9-]+$/', (string) $row->id)) {
            return;
        }
        try {
            \Illuminate\Support\Facades\File::deleteDirectory(self::workRoot((string) $row->id));
        } catch (\Throwable) {
            // The recording result stands; a leftover work directory is only clutter.
        }
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
        $name = $this->mirrorDiskName();
        if ($name === null) {
            throw new RecordingFailure('mirror-disabled', RecordingError::MirrorDisabledLocalRecordingNeedsEnabledLocalMirror);
        }

        return Storage::disk($name);
    }

    /** The configured mirror disk's name when it is a LOCAL disk (the only kind the recording writes and commits), else null. */
    private function mirrorDiskName(): ?string
    {
        $name = config('beam.ux.storage.mirror_disk');

        return is_string($name) && $name !== '' && config('filesystems.disks.'.$name.'.driver') === 'local' ? $name : null;
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
