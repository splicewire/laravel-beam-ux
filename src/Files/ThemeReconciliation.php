<?php

namespace Splicewire\Beam\Ux\Files;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Data\FileBatchReconcileInputData;
use Splicewire\Beam\Ux\Data\FileBatchReconcileResultData;
use Splicewire\Beam\Ux\Data\FileDraftDiagnosticData;
use Splicewire\Beam\Ux\Data\FileInspectionData;
use Splicewire\Beam\Ux\Data\FileReconcileInputData;
use Splicewire\Beam\Ux\Data\FileReconcileResultData;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\JsonDoc\SourceToBlocks;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Recording\ReleaseRecording;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Fixed Theme/CSS and Page/MDX dispatch over the same guarded current-file orchestration. A Page/MDX that holds blocks
 * (ADR-0233 d1) reconciles its {@see MdxPageFile}: page settings as frontmatter, then the body the MDX Lens prints.
 */
class ThemeReconciliation
{
    public function __construct(private LocalThemeFile $files, private CanonicalTheme $themes, private CanonicalMdx $mdx = new CanonicalMdx) {}

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
            $format = $entry->getAttribute('format');
            $data->format = $format instanceof UxFormat ? $format->value : null;
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
                $this->decodeFile($entry, $file['bytes'], $this->pinnedBody($entry));
                $data->state = 'differs';
                $data->message = 'The local file differs from the current published version. Take it as a draft deliberately.';
                $data->canTake = true;
                $data->canRemirror = $this->mayRestore($entry, $actor);
            }
        } catch (FileConflict $failure) {
            $data->state = in_array($failure->reason, ['invalid', 'valid-empty-outside-import'], true) ? $failure->reason : 'unsupported';
            $data->message = $failure->getMessage();
        }

        $batchItems = $this->takeAllSnapshot($member, $actor);
        $data->takeAllItems = $batchItems;
        $data->canTakeAll = count($batchItems) > 0;

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
            [$body, $settings] = $this->decodeFile($locked, $file['bytes'], $this->pinnedBody($locked));
            $this->sameTarget($locked, $target);
            $again = $this->files->read($target);
            if ($again !== $file) {
                throw new FileConflict('file-moved', 'The local file changed during validation. Nothing was imported. Reload comparison.');
            }
            $draft = app(EntryPublication::class)->draftFromBase($locked, $body, $input->expectedHeadVersion,
                $this->isMdx($locked) || MdxPageFile::applies($locked) ? 'Taken from canonical local MDX file' : 'Taken from local Theme file',
                $actor instanceof \Illuminate\Database\Eloquent\Model ? $actor : null, $settings);
            if ($draft->compileError !== null) {
                $diagnostic = FileDraftDiagnosticData::compileFailed();

                return new FileReconcileResultData('drafted-with-compile-error', $diagnostic->message,
                    $locked->getAttribute('id'), $draft->headVersion, $draft->publishedVersion, $diagnostic);
            }

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

    public function remirror(BeamUxReleaseMember $member, Authenticatable $actor, FileReconcileInputData $input): FileReconcileResultData
    {
        $entry = $this->entry($member, $actor, true);

        return $entry->getConnection()->transaction(function () use ($entry, $input, $member, $actor) {
            $this->serializeEntry($entry);
            $locked = $entry->newQuery()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->entry($member, $actor, true);
            [$locked, $state, $target, $published] = $this->context($locked);
            $file = $this->check($input, $state, $target);
            if (! $file['exists']) {
                throw new FileConflict('file-moved', 'The local file is missing. Use Restore after a fresh comparison.');
            }
            if ($file['bytes'] === $published) {
                return new FileReconcileResultData('unchanged', 'The local file already matches the published version. Nothing changed.', $locked->getAttribute('id'), $state->headVersion, $state->publishedVersion);
            }
            $this->sameTarget($locked, $target);
            $again = $this->files->read($target);
            if ($again !== $file) {
                throw new FileConflict('file-moved', 'The local file changed during validation. Nothing was overwritten. Reload comparison.');
            }
            $this->files->replace($target, $published, $file);

            return new FileReconcileResultData('remirrored', 'The local file was replaced with the current published version. Draft and history are unchanged.', $locked->getAttribute('id'), $state->headVersion, $state->publishedVersion);
        });
    }

    public function takeAll(BeamUxReleaseMember $anchor, Authenticatable $actor, FileBatchReconcileInputData $input): FileBatchReconcileResultData
    {
        if (! $input->confirmed) {
            throw new FileConflict('confirmation', 'Explicit confirmation of the inspected batch is required.', 422);
        }
        $this->entry($anchor, $actor);
        $release = BeamUxRelease::on($anchor->getConnectionName())->findOrFail($anchor->release_id);
        $expected = $this->takeAllSnapshot($anchor, $actor);
        $provided = $input->items;
        usort($provided, fn (array $left, array $right): int => strcmp($left['memberId'], $right['memberId']));
        $expectedTokens = array_map(fn (array $item): array => array_diff_key($item, ['path' => true]), $expected);
        if (count($expected) < 1 || $provided !== $expectedTokens) {
            throw new FileConflict('file-moved', 'The Files changed since the batch comparison. Nothing was taken. Reload comparison.');
        }

        return $release->getConnection()->transaction(function () use ($anchor, $actor, $release, $expected, $provided) {
            $members = BeamUxReleaseMember::on($release->getConnectionName())
                ->where('release_id', $release->id)->whereIn('id', array_column($provided, 'memberId'))->get()->keyBy('id');
            if ($members->count() !== count($provided)) {
                throw new FileConflict('member-moved', 'A Release member changed since comparison. Nothing was taken.');
            }
            $entries = [];
            foreach ($provided as $item) {
                $member = $members[$item['memberId']];
                $entries[] = [$member, $this->entry($member, $actor)];
            }
            usort($entries, fn (array $left, array $right): int => strcmp((string) $left[1]->getKey(), (string) $right[1]->getKey()));
            foreach ($entries as [, $entry]) {
                $this->serializeEntry($entry);
            }
            $drafts = [];
            foreach ($entries as [$member, $entry]) {
                $locked = $entry->newQuery()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                [$locked, $state, $target] = $this->context($locked);
                $item = collect($provided)->first(fn (array $candidate) => $candidate['memberId'] === (string) $member->id);
                $token = new FileReconcileInputData($item['expectedHeadVersion'], $item['expectedPublishedVersion'],
                    $item['expectedFileExists'], $item['expectedFileSha256'], $item['fingerprint'], true);
                $file = $this->check($token, $state, $target);
                if (! $file['exists']) {
                    throw new FileConflict('file-moved', 'A local file is missing. Nothing was taken. Reload comparison.');
                }
                [$body, $settings] = $this->decodeFile($locked, $file['bytes'], $this->pinnedBody($locked));
                $drafts[] = [$locked, $body, $settings, $token, $target, $file];
            }
            if ($this->takeAllSnapshot($anchor, $actor) !== $expected) {
                throw new FileConflict('file-moved', 'The Files changed during batch validation. Nothing was taken. Reload comparison.');
            }
            $results = [];
            foreach ($drafts as [$entry, $body, $settings, $token, $target, $file]) {
                if ($this->files->target($entry) !== $target || $this->files->read($target) !== $file) {
                    throw new FileConflict('file-moved', 'A local file changed during batch validation. Nothing was taken. Reload comparison.');
                }
                $draft = app(EntryPublication::class)->draftFromBase($entry, $body, $token->expectedHeadVersion,
                    'Taken from local file as part of a confirmed batch', $actor instanceof \Illuminate\Database\Eloquent\Model ? $actor : null, $settings);
                $diagnostic = $draft->compileError === null ? null : FileDraftDiagnosticData::compileFailed();
                $results[] = new FileReconcileResultData($diagnostic === null ? 'drafted' : 'drafted-with-compile-error',
                    $diagnostic?->message ?? 'Draft saved. The published version and local file are unchanged.',
                    $entry->getAttribute('id'), $draft->headVersion, $draft->publishedVersion, $diagnostic);
            }

            return new FileBatchReconcileResultData(count($results), $results);
        });
    }

    /** @return list<array{memberId: string, expectedHeadVersion: string, expectedPublishedVersion: string, expectedFileExists: bool, expectedFileSha256: string, fingerprint: string, path: string}> */
    private function takeAllSnapshot(BeamUxReleaseMember $anchor, Authenticatable $actor): array
    {
        $release = BeamUxRelease::on($anchor->getConnectionName())->findOrFail($anchor->release_id);
        $items = [];
        $blocked = false;
        $members = $release->members()->orderBy('entry_id')->limit(51)->get();
        if ($members->count() > 50) {
            return [];
        }
        foreach ($members as $member) {
            try {
                $entry = $this->entry($member, $actor);
                [$entry, $state, $target, $published] = $this->context($entry);
                $file = $this->files->read($target);
                if (! $file['exists'] || $file['bytes'] === $published) {
                    continue;
                }
                $this->decodeFile($entry, $file['bytes'], $this->pinnedBody($entry));
                if ($state->headVersion === null || $state->publishedVersion === null || $file['hash'] === null) {
                    $blocked = true;

                    continue;
                }
                $items[] = ['memberId' => (string) $member->id, 'expectedHeadVersion' => $state->headVersion,
                    'expectedPublishedVersion' => $state->publishedVersion, 'expectedFileExists' => true,
                    'expectedFileSha256' => $file['hash'], 'fingerprint' => $target['fingerprint'], 'path' => $target['path']];
            } catch (\Throwable) {
                $blocked = true;
            }
        }
        usort($items, fn (array $left, array $right): int => strcmp($left['memberId'], $right['memberId']));

        return $blocked ? [] : $items;
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
        if (! $this->isMdx($entry) && ! MdxPageFile::applies($entry) && ($entry->getAttribute('type') !== UxType::Theme || $entry->codec()::class !== CssBodyCodec::class)) {
            throw new FileConflict('unsupported', 'Only canonical Theme/CSS or body-only Page/MDX with its native codec is supported here.', 422);
        }
        $state = app(EntryPublication::class)->state($entry);
        if ($state->headVersion === null || $state->publishedVersion === null) {
            throw new FileConflict('unsupported', 'A valid existing working head and published pin are required.', 422);
        }
        $body = $this->pinnedBody($entry);
        $published = $this->published($entry, $body);
        if ($this->isMdx($entry) || MdxPageFile::applies($entry)) {
            // The existing code/profile guard remains authoritative; losslessness is not admission.
            app(CanvasBodyGuard::class)->assertAdmitted($entry, $body);
        }
        if (strlen($published) > LocalThemeFile::LIMIT || ! mb_check_encoding($published, 'UTF-8')) {
            throw new FileConflict('unsupported', 'The published representation is not bounded UTF-8.', 422);
        }

        return [$entry, $state, $this->files->target($entry), $published];
    }

    private function isMdx(BeamUxEntry $entry): bool
    {
        return $entry->getAttribute('type') === UxType::Page && $entry->getAttribute('format') === UxFormat::Mdx
            && $entry->codec()::class === MdxBodyCodec::class && $entry->bodyKind() === BodyKind::Source;
    }

    /** The current published representation: the bytes the mirror writes for this pinned body.
     * @param  array<string, mixed>  $body
     */
    private function published(BeamUxEntry $entry, array $body): string
    {
        if ($this->isMdx($entry)) {
            return $this->mdx->published($body);
        }
        if (! MdxPageFile::applies($entry)) {
            return $entry->codec()->decode($body);
        }
        if (! JsonDocShape::is($body)) {
            throw new FileConflict('unsupported', 'This MDX page still holds its source. Its host converts it to blocks once (ADR-0233 d9) before the Files action reads it.', 422);
        }

        return MdxPageFile::print(MdxPageFile::settingsOf($entry), $entry->codec()->decode($body));
    }

    /** The body a file drafts, and for a blocks MDX page the page settings its header changed (else null).
     * @param  array<string, mixed>  $published
     * @return array{0: array<string, mixed>, 1: ?EntryPageSettingsData}
     */
    private function decodeFile(BeamUxEntry $entry, string $bytes, array $published): array
    {
        if (MdxPageFile::applies($entry)) {
            return $this->decodeMdxPageFile($entry, $bytes, $published);
        }

        return [$this->isMdx($entry) ? $this->mdx->decode($bytes, $published) : $this->themes->decode($bytes, $published), null];
    }

    /**
     * ADR-0233 d3: the header is the page's settings and the rest is its body. A header key no page setting declares
     * is kept with the settings as written; the body reads through the MDX Lens with ids reattached against the
     * published blocks the file was printed from.
     *
     * @param  array<int, array<string, mixed>>  $published
     * @return array{0: array<int, array<string, mixed>>, 1: ?EntryPageSettingsData}
     */
    private function decodeMdxPageFile(BeamUxEntry $entry, string $bytes, array $published): array
    {
        if (strlen($bytes) > CanonicalMdx::LIMIT || ! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\r")) {
            throw new FileConflict('invalid', 'MDX import requires bounded UTF-8 with LF line endings.', 422);
        }
        $file = MdxPageFile::read($bytes);
        $served = MdxPageFile::settingsOf($entry);
        $header = [$file['fields'], $file['unknown']];
        $current = [MdxPageFile::fields($served), $served->unclaimed];
        array_walk($header, fn (array &$keys) => ksort($keys));
        array_walk($current, fn (array &$keys) => ksort($keys));
        try {
            $settings = $header === $current ? null : MdxPageFile::settingsFrom($file['fields'], $entry->parent_id, $file['unknown']);
            if ($settings !== null) {
                MdxPageFile::assertPrintable($settings);
            }
            $body = app(SourceToBlocks::class)->convert($file['body'], $published, app(ManifestProfileResolver::class)->resolve($entry), UxFormat::Mdx);
        } catch (ValidationException $failure) {
            throw new FileConflict('invalid', (string) (collect($failure->errors())->flatten()->first() ?? 'The MDX page file is not valid.'), 422);
        }

        return [$body, $settings];
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
