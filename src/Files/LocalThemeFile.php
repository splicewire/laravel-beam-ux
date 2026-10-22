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
            throw new FileConflict('unsupported', 'File reconciliation needs an enabled local mirror disk.', 422);
        }
        $disk = \Illuminate\Support\Facades\Storage::disk($diskName);
        $root = rtrim($disk->path(''), '/');
        $path = app(PlacementResolver::class)->resolve($entry)->pathFor($entry);
        if (! str_starts_with($root, '/') || $path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new FileConflict('unsupported', 'The local mirror path cannot be verified.', 422);
        }
        $parts = [...explode('/', ltrim($root, '/')), ...explode('/', $path)];
        $cursor = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
                throw new FileConflict('unsupported', 'The local mirror path is not contained.', 422);
            }
            $parent = $cursor === '' ? '/' : $cursor;
            if (is_dir($parent)) {
                $names = scandir($parent);
                if ($names === false) {
                    throw new FileConflict('unsupported', 'The local mirror path cannot be read.', 422);
                }
                foreach ($names as $name) {
                    if (strtolower($name) === strtolower($part) && $name !== $part) {
                        throw new FileConflict('unsupported', 'A local mirror path has a case alias.', 422);
                    }
                }
            }
            $cursor .= '/'.$part;
            if (is_link($cursor) || (file_exists($cursor) && ! is_dir($cursor) && $cursor !== $root.'/'.$path)) {
                throw new FileConflict('unsupported', 'Symlink and non-directory mirror ancestors are unsupported.', 422);
            }
            if (str_starts_with($cursor, $root.'/') && is_dir($cursor) && file_exists($cursor.'/.git')) {
                throw new FileConflict('unsupported', 'Nested repositories and submodule mirrors are unsupported.', 422);
            }
        }
        if (file_exists($cursor) && ! is_file($cursor)) {
            throw new FileConflict('unsupported', 'Only regular local mirror files are supported.', 422);
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
                    throw new FileConflict('unsupported', 'Submodule placement cannot be verified.', 422);
                }
                $relative = substr($cursor, strlen($parent) + 1);
                foreach ($modules as $module) {
                    $sub = is_array($module) ? ($module['path'] ?? null) : null;
                    if (is_string($sub) && ($relative === $sub || str_starts_with($relative, $sub.'/'))) {
                        throw new FileConflict('unsupported', 'Submodule mirrors are unsupported.', 422);
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
            throw new FileConflict('unsupported', 'Symlink mirrors are unsupported.', 422);
        }
        if (! file_exists($path)) {
            return ['exists' => false, 'bytes' => null, 'hash' => null];
        }
        if (! is_file($path)) {
            throw new FileConflict('unsupported', 'Only regular local mirror files are supported.', 422);
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new FileConflict('invalid', 'The local mirror cannot be read.', 422);
        }
        try {
            $stat = fstat($stream);
            $bytes = stream_get_contents($stream, self::LIMIT + 1);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (! is_array($current) || ! is_array($stat) || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev'] || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $bytes === false || strlen($bytes) > self::LIMIT) {
                throw new FileConflict('invalid', 'The local mirror is not a bounded readable regular file.', 422);
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
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new FileConflict('incomplete', 'The mirror directory could not be created. Nothing was replaced.', 503);
        }
        $this->checkAncestors($path);
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            throw new FileConflict('file-moved', 'A local file appeared or could not be created. Nothing was overwritten. Reload comparison.');
        }
        try {
            $written = 0;
            while ($written < strlen($bytes)) {
                $count = $this->write($stream, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new FileConflict('incomplete', 'Restore could not complete. The created file is preserved; inspect or move it before trying again.', 503);
                }
                $written += $count;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new FileConflict('incomplete', 'Restore could not confirm durable bytes. The created file is preserved; inspect it before retrying.', 503);
            }
            rewind($stream);
            $actual = stream_get_contents($stream, self::LIMIT + 1);
            $opened = fstat($stream);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($actual !== $bytes || ! is_array($current) || ! is_array($opened) || $current['ino'] !== $opened['ino'] || $current['dev'] !== $opened['dev'] || is_link($path)
                || $this->read($target)['bytes'] !== $bytes) {
                throw new FileConflict('incomplete', 'Restore result changed or could not be verified. Preserve the local file and inspect before retrying.', 503);
            }
        } finally {
            fclose($stream);
        }
    }

    private function checkAncestors(string $path): void
    {
        clearstatcache();
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            if (is_link($parent) || ! is_dir($parent)) {
                throw new FileConflict('placement-moved', 'The mirror directory changed. Nothing was overwritten.');
            }
        }
    }

    /** @param resource $stream */
    protected function write($stream, string $bytes): int|false
    {
        return fwrite($stream, $bytes);
    }
}
