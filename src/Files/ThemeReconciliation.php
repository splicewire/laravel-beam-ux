<?php

namespace Splicewire\Beam\Ux\Files;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Data\FileInspectionData;
use Splicewire\Beam\Ux\Data\FileReconcileInputData;
use Splicewire\Beam\Ux\Data\FileReconcileResultData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Type\UxType;

/** Current-file reconciliation is separate from the addressed Release's immutable recording. */
class ThemeReconciliation
{
    public function __construct(private LocalThemeFile $files, private CanonicalTheme $themes) {}

    public function inspect(BeamUxReleaseMember $member, Authenticatable $actor): FileInspectionData
    {
        $entry = $this->entry($member, $actor);
        $data = new FileInspectionData($member->id, $entry->getAttribute('id'), 'unsupported', 'This file cannot be reconciled.');
        try {
            [$entry, $state, $target, $published] = $this->context($entry);
            $data->workingHeadVersion = $state->headVersion;
            $data->publishedVersion = $state->publishedVersion;
            $data->fingerprint = $target['fingerprint'];
            $data->path = $target['path'];
            $file = $this->files->read($target);
            $this->sameTarget($entry, $target);
            $data->fileExists = $file['exists'];
            $data->fileSha256 = $file['hash'];
            $data->sourceTruncated = strlen($published) > 20000 || strlen($file['bytes'] ?? '') > 20000;
            $data->publishedSource = mb_strcut($published, 0, 20000, 'UTF-8');
            $data->localSource = $file['bytes'] !== null && mb_check_encoding($file['bytes'], 'UTF-8') ? mb_strcut($file['bytes'], 0, 20000, 'UTF-8') : null;
            if (! $file['exists']) {
                $data->state = 'missing';
                $data->message = 'The local file is missing. Restore creates only the current published bytes.';
                $data->canRestore = $this->mayRestore($entry, $actor);
            } elseif ($file['bytes'] === $published) {
                $data->state = 'unchanged';
                $data->message = 'The local file matches the current published version.';
            } else {
                $this->themes->decode($file['bytes'], $this->pinnedBody($entry));
                $data->state = 'differs';
                $data->message = 'The local file differs from the current published version. Take it as a draft deliberately.';
                $data->canTake = true;
            }
        } catch (FileConflict $failure) {
            $data->state = in_array($failure->reason, ['invalid', 'valid-empty-outside-import'], true) ? $failure->reason : 'unsupported';
            $data->message = $failure->getMessage();
        }

        return $data;
    }

    public function take(BeamUxReleaseMember $member, Authenticatable $actor, FileReconcileInputData $input): FileReconcileResultData
    {
        $entry = $this->entry($member, $actor);

        return $entry->getConnection()->transaction(function () use ($entry, $input, $member, $actor) {
            $this->serializeEntry($entry);
            $locked = $entry->newQuery()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->entry($member, $actor);
            [$locked, $state, $target] = $this->context($locked);
            $file = $this->check($input, $state, $target);
            if (! $file['exists']) {
                throw new FileConflict('file-moved', 'The local file is missing. Nothing was imported.');
            }
            $body = $this->themes->decode($file['bytes'], $this->pinnedBody($locked));
            $this->sameTarget($locked, $target);
            $again = $this->files->read($target);
            if ($again !== $file) {
                throw new FileConflict('file-moved', 'The local file changed during validation. Nothing was imported. Reload comparison.');
            }
            $draft = app(EntryPublication::class)->draftFromBase($locked, $body, $input->expectedHeadVersion, 'Taken from local Theme file', $actor instanceof \Illuminate\Database\Eloquent\Model ? $actor : null);

            return new FileReconcileResultData('drafted', 'Draft saved. The published version and local file are unchanged.', $locked->getAttribute('id'), $draft->headVersion, $draft->publishedVersion);
        });
    }

    public function restore(BeamUxReleaseMember $member, Authenticatable $actor, FileReconcileInputData $input): FileReconcileResultData
    {
        $entry = $this->entry($member, $actor, true);

        return $entry->getConnection()->transaction(function () use ($entry, $member, $actor, $input) {
            $this->serializeEntry($entry);
            $locked = $entry->newQuery()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->entry($member, $actor, true);
            [$locked, $state, $target, $published] = $this->context($locked);
            $file = $this->check($input, $state, $target);
            if ($file['exists']) {
                if ($file['bytes'] === $published) {
                    return new FileReconcileResultData('unchanged', 'The local file already matches the published version. Nothing changed.', $locked->getAttribute('id'), $state->headVersion, $state->publishedVersion);
                }
                throw new FileConflict('file-exists', 'A different local file exists. Preserve or move it before restoring a missing published file. Nothing was overwritten.');
            }
            $this->sameTarget($locked, $target);
            $this->files->create($target, $published);

            return new FileReconcileResultData('restored', 'The missing published file was restored. Draft, history and Git are unchanged.', $locked->getAttribute('id'), $state->headVersion, $state->publishedVersion);
        });
    }

    private function serializeEntry(BeamUxEntry $entry): void
    {
        // SQLite ignores FOR UPDATE. Reserve its writer before reading preconditions: a no-op on
        // this exact row shares the database writer lock used by publication, without changing data.
        if ($entry->getConnection()->getDriverName() === 'sqlite') {
            $entry->newQuery()->whereKey($entry->id)->toBase()->update(['id' => $entry->id]);
        }
    }

    private function entry(BeamUxReleaseMember $member, Authenticatable $actor, bool $restore = false): BeamUxEntry
    {
        $release = BeamUxRelease::on($member->getConnectionName())->findOrFail($member->release_id);
        try {
            app(ReleaseRecording::class)->authorize($release, $actor);
        } catch (\Splicewire\Beam\Ux\Recording\RecordingFailure $failure) {
            if ($failure->reason !== 'tenancy') {
                throw $failure;
            }
            throw new \Illuminate\Auth\Access\AuthorizationException('Local file reconciliation requires verified central residency with no active tenancy.');
        }
        $entry = BeamUxEntry::on($member->getConnectionName())->findOrFail($member->entry_id);
        app(ReleaseService::class)->authorizeFileEdit($entry, $actor, $restore);

        return $entry;
    }

    /** @return array{\Splicewire\Beam\Ux\Models\BeamUxEntry, \Splicewire\Beam\Ux\Data\EntryPublicationData, array{absolute: string, path: string, fingerprint: string}, string} */
    private function context(BeamUxEntry $entry): array
    {
        if ($entry->getAttribute('type') !== UxType::Theme || $entry->codec()::class !== CssBodyCodec::class) {
            throw new FileConflict('unsupported', 'Only the published canonical Theme/CSS representation is supported here.', 422);
        }
        $state = app(EntryPublication::class)->state($entry);
        if ($state->headVersion === null || $state->publishedVersion === null) {
            throw new FileConflict('unsupported', 'A valid existing working head and published pin are required.', 422);
        }
        $published = $entry->codec()->decode($this->pinnedBody($entry));
        if (strlen($published) > LocalThemeFile::LIMIT || ! mb_check_encoding($published, 'UTF-8')) {
            throw new FileConflict('unsupported', 'The published Theme representation is not bounded UTF-8.', 422);
        }

        return [$entry, $state, $this->files->target($entry), $published];
    }

    /** @return array<string, mixed> */
    private function pinnedBody(BeamUxEntry $entry): array
    {
        $version = $entry->particle_id === null || $entry->getAttribute('published_version') === null ? null
            : app(VersionStore::class)->version($entry->versionable(), $entry->getAttribute('published_version'));
        $body = $version?->snapshot['payload'] ?? null;
        if (! is_array($body)) {
            throw new FileConflict('unsupported', 'The current published snapshot cannot be verified.', 422);
        }

        return $body;
    }

    /** @param array{absolute: string, path: string, fingerprint: string} $target
     * @return array{exists: bool, bytes: ?string, hash: ?string}
     */
    private function check(FileReconcileInputData $input, \Splicewire\Beam\Ux\Data\EntryPublicationData $state, array $target): array
    {
        if (! $input->confirmed) {
            throw new FileConflict('confirmation', 'Explicit confirmation of this inspection is required.', 422);
        }
        if ($state->headVersion !== $input->expectedHeadVersion) {
            throw new FileConflict('head-moved', 'The working head changed since inspection. Nothing changed. Reload comparison.');
        }
        if ($state->publishedVersion !== $input->expectedPublishedVersion) {
            throw new FileConflict('pin-moved', 'The published version changed since inspection. Nothing changed. Reload comparison.');
        }
        if (! hash_equals($target['fingerprint'], $input->fingerprint)) {
            throw new FileConflict('placement-moved', 'The codec or file placement changed since inspection. Reload comparison.');
        }
        $file = $this->files->read($target);
        if ($file['exists'] !== $input->expectedFileExists || $file['hash'] !== $input->expectedFileSha256) {
            throw new FileConflict('file-moved', 'The local file changed since inspection. Nothing changed. Reload comparison.');
        }

        return $file;
    }

    /** @param array{absolute: string, path: string, fingerprint: string} $target */
    private function sameTarget(BeamUxEntry $entry, array $target): void
    {
        if ($this->files->target($entry) !== $target) {
            throw new FileConflict('placement-moved', 'The local file placement changed during inspection. Nothing changed.');
        }
    }

    private function mayRestore(BeamUxEntry $entry, Authenticatable $actor): bool
    {
        try {
            app(ReleaseService::class)->authorizeFileEdit($entry, $actor, true);

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }
}
