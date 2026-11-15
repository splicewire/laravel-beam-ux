<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store every read and write of which throws, the shape of a host whose configured store is down or
 * misconfigured (config-sections 05: the operator tier's override counter lives in a named cache store).
 */
final class UnreachableCacheStore implements Store
{
    public function get($key)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function many(array $keys)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function put($key, $value, $seconds)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function putMany(array $values, $seconds)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function increment($key, $value = 1)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function decrement($key, $value = 1)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function forever($key, $value)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function touch($key, $seconds)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function forget($key)
    {
        throw new RuntimeException('cache unreachable');
    }

    public function flush()
    {
        throw new RuntimeException('cache unreachable');
    }

    public function getPrefix()
    {
        return '';
    }
}
