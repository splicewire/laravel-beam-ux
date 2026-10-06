<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;

class RecordingDriver implements StorageDriver
{
    public static bool $diskIsNewer = true;

    /** @var array<string, array<string, mixed>> */
    public array $written = [];

    public function read(string $key): ?StorageItem
    {
        return isset($this->written[$key]) ? new StorageItem($key, $this->written[$key]) : null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'p-'.(count($this->written) + 1);
        $this->written[$key] = $body;

        return new StorageItem($key, $body, $namespace, time());
    }

    public function list(?string $namespace = null): array
    {
        return [];
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return self::$diskIsNewer ? 1 : -1;
    }
}
