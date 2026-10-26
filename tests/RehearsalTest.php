<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Ux\Convert\Rehearsal;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;

/**
 * A rehearsal puts back exactly what it swapped (review-r1, EP-42 dry-run fidelity): the container and the facades agree
 * afterwards, and a rehearsal that fails to start leaves nothing swapped and no transaction open.
 */
class RehearsalTest extends TestCase
{
    public function test_the_cache_container_and_facade_agree_after_a_rehearsal_that_found_the_cache_unresolved(): void
    {
        // A process that hasn't touched the cache yet: its bindings are registered, nothing is resolved.
        $this->app->offsetUnset('cache');
        $this->app->offsetUnset('cache.store');
        $this->app->singleton('cache', fn ($app) => new CacheManager($app));
        $this->app->singleton('cache.store', fn ($app) => $app['cache']->driver());
        Cache::clearResolvedInstance('cache');

        app(Rehearsal::class)->run(fn () => Cache::put('rehearsed', true));

        $this->assertSame($this->app->make('cache'), Cache::getFacadeRoot(), 'one cache manager, whoever asks');
        $this->assertSame($this->app->make('cache')->driver(), $this->app->make('cache.store'));
        $this->assertFalse(Cache::has('rehearsed'), 'the rehearsal\'s cache is gone');
    }

    public function test_a_rehearsal_that_fails_to_begin_leaves_nothing_swapped_and_no_transaction_open(): void
    {
        // The second connection can't open: its SQLite file doesn't exist, so its begin fails after the first has begun.
        config(['database.connections.unreachable' => ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/no-such-dir/rehearsal.sqlite', 'prefix' => '']]);
        $default = config('database.default');
        $level = DB::connection($default)->transactionLevel();
        $mirror = app(PlacedDiskMirror::class);
        $bus = Bus::getFacadeRoot();
        $cacheDefault = config('cache.default');
        $ran = false;

        try {
            (new Rehearsal($this->app, [$default, 'unreachable']))->run(function () use (&$ran) {
                $ran = true;
            });
            $this->fail('the unreachable connection should not begin');
        } catch (\Throwable $failed) {
            $this->assertStringNotContainsString('should not begin', $failed->getMessage());
        }

        $this->assertFalse($ran, 'the work never ran');
        $this->assertSame($level, DB::connection($default)->transactionLevel(), 'the connection that began is rolled back');
        $this->assertSame($mirror, app(PlacedDiskMirror::class));
        $this->assertSame($bus, Bus::getFacadeRoot());
        $this->assertSame($cacheDefault, config('cache.default'));
    }
}
