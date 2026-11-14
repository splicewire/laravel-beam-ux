<?php

namespace Splicewire\Beam\Ux\Files;

use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\PlacementResolver;

/** Contained local mirror I/O, independent of Git discovery/status. Never replaces or deletes a file. */
class LocalThemeFile
{
    public const LIMIT = 4194304;

    /** @return array{absolute: string, path: string, fingerprint: string} */
    public function target(BeamUxEntry $entry): array
    {
        clearstatcache();
        $diskName = config('beam.ux.storage.mirror_disk');
        if (! is_string($diskName) || config('filesystems.disks.'.$diskName.'.driver') !== 'local') {
            throw new FileConflict('unsupported', FileError::UnsupportedFileReconciliationNeedsEnabledLocalMirror, 422);
        }
        $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
        $root = rtrim($disk->path(''), '/');
        $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
        if (! str_starts_with($root, '/') || $path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new FileConflict('unsupported', FileError::UnsupportedLocalMirrorPathCannotVerified, 422);
        }
        $parts = [...explode('/', ltrim($root, '/')), ...explode('/', $path)];
        $cursor = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new FileConflict('unsupported', FileError::UnsupportedLocalMirrorPathNotContained, 422);
            }
            $parent = $cursor === '' ? '/' : $cursor;
            if (is_dir($parent)) {
                $names = scandir($parent);
                if ($names === false) {
                    throw new FileConflict('unsupported', FileError::UnsupportedLocalMirrorPathCannotRead, 422);
                }
                foreach ($names as $name) {
                    if (strtolower($name) === strtolower($part) && $name !== $part) {
                        throw new FileConflict('unsupported', FileError::UnsupportedLocalMirrorPathHasCaseAlias, 422);
                    }
                }
            }
            $cursor .= '/'.$part;
            if (is_link($cursor) || (file_exists($cursor) && ! is_dir($cursor) && $cursor !== $root.'/'.$path)) {
                throw new FileConflict('unsupported', FileError::UnsupportedSymlinkAndNonDirectoryMirrorAncestors, 422);
            }
            if (str_starts_with($cursor, $root.'/') && is_dir($cursor) && file_exists($cursor.'/.git')) {
                throw new FileConflict('unsupported', FileError::UnsupportedNestedRepositoriesAndSubmoduleMirrorsUnsupported, 422);
            }
        }
        if (file_exists($cursor) && ! is_file($cursor)) {
            throw new FileConflict('unsupported', FileError::UnsupportedOnlyRegularLocalMirrorFilesSupported, 422);
        }
        // An uninitialized submodule can lack a nested .git marker. Read its declared path without
        // invoking Git, so an unavailable Git executable does not disable comparison/import.
        for ($parent = dirname($cursor); $parent !== '/'; $parent = dirname($parent)) {
            if (! file_exists($parent.'/.git')) {
                continue;
            }
            if (is_file($parent.'/.gitmodules')) {
                $modules = @parse_ini_file($parent.'/.gitmodules', true, INI_SCANNER_RAW);
                if (! is_array($modules)) {
                    throw new FileConflict('unsupported', FileError::UnsupportedSubmodulePlacementCannotVerified, 422);
                }
                $relative = substr($cursor, strlen($parent) + 1);
                foreach ($modules as $module) {
                    $sub = is_array($module) ? ($module['path'] ?? null) : null;
                    if (is_string($sub) && ($relative === $sub || str_starts_with($relative, $sub.'/'))) {
                        throw new FileConflict('unsupported', FileError::UnsupportedSubmoduleMirrorsUnsupported, 422);
                    }
                }
            }
            break;
        }

        return ['absolute' => $cursor, 'path' => $path, 'fingerprint' => hash('sha256', json_encode([
            $entry->id, $entry->getAttribute('format')?->value, $entry->getAttribute('type')?->value, $entry->codec()::class, $diskName, $root, $path,
        ], JSON_THROW_ON_ERROR))];
    }

    /** @param array{absolute: string, path: string, fingerprint: string} $target
     * @return array{exists: bool, bytes: ?string, hash: ?string}
     */
    public function read(array $target): array
    {
        $path = $target['absolute'];
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new FileConflict('unsupported', FileError::UnsupportedSymlinkMirrorsUnsupported, 422);
        }
        if (! file_exists($path)) {
            return ['exists' => false, 'bytes' => null, 'hash' => null];
        }
        if (! is_file($path)) {
            throw new FileConflict('unsupported', FileError::UnsupportedOnlyRegularLocalMirrorFilesSupported, 422);
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new FileConflict('invalid', FileError::InvalidLocalMirrorCannotRead, 422);
        }
        try {
            $stat = @fstat($stream);
            $bytes = @stream_get_contents($stream, self::LIMIT + 1);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (! is_array($current) || ! is_array($stat) || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev'] || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $bytes === false || strlen($bytes) > self::LIMIT) {
                throw new FileConflict('invalid', FileError::InvalidLocalMirrorNotBoundedReadableRegular, 422);
            }
        } finally {
            fclose($stream);
        }

        return ['exists' => true, 'bytes' => $bytes, 'hash' => hash('sha256', $bytes)];
    }

    /** Exclusive create only. Ambiguous partial files are preserved for explicit human recovery.
     * @param  array{absolute: string, path: string, fingerprint: string}  $target
     */
    public function create(array $target, string $bytes): void
    {
        $path = $target['absolute'];
        // Refuse known changed ancestors before mkdir can follow a symlink outside the root.
        $this->checkAncestors($path, allowMissing: true);
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new FileConflict('incomplete', FileError::IncompleteMirrorDirectoryCouldNotCreatedNothing, 503);
        }
        $this->checkAncestors($path);
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            throw new FileConflict('file-moved', FileError::FileMovedLocalFileAppearedOrCouldNot);
        }
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = $this->write($stream, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new FileConflict('incomplete', FileError::IncompleteRestoreCouldNotCompleteCreatedFile, 503);
                }
                $written += $count;
            }
            if (! @fflush($stream) || ! @fsync($stream)) {
                throw new FileConflict('incomplete', FileError::IncompleteRestoreCouldNotConfirmDurableBytes, 503);
            }
            @rewind($stream);
            $actual = @stream_get_contents($stream, self::LIMIT + 1);
            $opened = @fstat($stream);
            clearstatcache(true, $path);
            $current = @lstat($path);
            try {
                $readback = $this->read($target)['bytes'];
            } catch (FileConflict $failure) {
                throw new FileConflict('incomplete', FileError::IncompleteCreatedFileCannotVerifiedPreserveAnd, 503);
            }
            if ($actual !== $bytes || ! is_array($current) || ! is_array($opened) || $current['ino'] !== $opened['ino'] || $current['dev'] !== $opened['dev'] || is_link($path)
                || $readback !== $bytes) {
                throw new FileConflict('incomplete', FileError::IncompleteRestoreResultChangedOrCouldNot, 503);
            }
        } finally {
            fclose($stream);
        }
    }

    /** Atomic, explicit replacement of one inspected regular file with the current published bytes.
     * @param  array{absolute: string, path: string, fingerprint: string}  $target
     * @param  array{exists: bool, bytes: ?string, hash: ?string}  $expected
     */
    public function replace(array $target, string $bytes, array $expected): void
    {
        $path = $target['absolute'];
        $before = $this->read($target);
        if (! $expected['exists'] || $before !== $expected) {
            throw new FileConflict('file-moved', FileError::FileMovedLocalFileChangedBeforeReMirror);
        }
        $this->checkAncestors($path);
        $temporary = dirname($path).'/.'.basename($path).'.beam-remirror-'.bin2hex(random_bytes(8));
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new FileConflict('incomplete', FileError::IncompleteTemporaryMirrorFileCouldNotCreated, 503);
        }
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = $this->write($stream, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new FileConflict('incomplete', FileError::IncompleteReMirrorCouldNotCompleteTemporary, 503);
                }
                $written += $count;
            }
            if (! @fflush($stream) || ! @fsync($stream)) {
                throw new FileConflict('incomplete', FileError::IncompleteReMirrorCouldNotConfirmDurable, 503);
            }
            $mode = fileperms($path);
            if (is_int($mode) && (! @chmod($temporary, $mode & 0777)
                || ((fileperms($temporary) ?: 0) & 0777) !== ($mode & 0777))) {
                throw new FileConflict('incomplete', FileError::IncompleteReMirrorCouldNotPreserveLocal, 503);
            }
            fclose($stream);
            $stream = null;
            if ($this->read($target) !== $expected) {
                throw new FileConflict('file-moved', FileError::FileMovedLocalFileChangedBeforeReMirror);
            }
            $this->checkAncestors($path);
            if (! @rename($temporary, $path)) {
                throw new FileConflict('incomplete', FileError::IncompleteVerifiedReplacementCouldNotInstalledOriginal, 503);
            }
            if ($this->read($target)['bytes'] !== $bytes) {
                throw new FileConflict('incomplete', FileError::IncompleteReMirrorResultCouldNotVerified, 503);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function checkAncestors(string $path, bool $allowMissing = false): void
    {
        clearstatcache();
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            if (is_link($parent) || (file_exists($parent) && ! is_dir($parent)) || (! $allowMissing && ! is_dir($parent))) {
                throw new FileConflict('placement-moved', FileError::PlacementMovedMirrorDirectoryChangedNothingWasOverwritten);
            }
        }
    }

    /** @param resource $stream */
    protected function write($stream, string $bytes): int|false
    {
        return @fwrite($stream, $bytes);
    }
}
