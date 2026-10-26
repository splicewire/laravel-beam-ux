<?php

namespace Splicewire\Beam\Ux\Convert;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Components\ComponentScope;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;

/**
 * Runs work as a rehearsal: it does what it would do, and none of it stays (EP-42, orch-lead 2026-10-26). convert-mdx's
 * dry run is the report an owner decides an adoption on (R2), and before this it stopped short of the writes, so it
 * could not see a page the write refuses. A rehearsal runs the real writes instead, and contains them:
 *
 * - the database: one transaction per connection the entries and Releases use, always rolled back, which also drops
 *   every `afterCommit` callback registered inside it;
 * - the files beam-ux writes: the disk mirror is a no-op, and artifacts compile into a temporary root removed after.
 *   The compile still runs, since a body that doesn't compile is refused;
 * - everything a write sets off outside the database, beam-ux's or the host's own: job dispatches (queued or run now,
 *   broadcasts among them), queue pushes, mail and notifications are faked, broadcasting has the null driver, and the
 *   cache is a fresh array store.
 *
 * Model events still fire, since writes depend on them (a new entry's `realms`). Each swapped service is put back as it
 * was, and the in-process memos a write fills are forgotten after the rollback. It can't see what happens between it and
 * the real run, such as another author's edit or a release published meanwhile.
 */
final class Rehearsal
{
    /** Services a write reaches through the container, swapped for the rehearsal and put back after. */
    private const SWAPPED = [PlacedDiskMirror::class, EntryArtifactStore::class, CompileEntryBody::class, 'cache', 'cache.store'];

    public function __construct(private Application $app) {}

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $saved = [];
        foreach (self::SWAPPED as $abstract) {
            $saved[$abstract] = $this->app->resolved($abstract) ? $this->app->make($abstract) : null;
        }
        $facades = ['bus' => Bus::getFacadeRoot(), 'queue' => Queue::getFacadeRoot(), 'mail' => Mail::getFacadeRoot(),
            'notification' => Notification::getFacadeRoot(), 'cache' => Cache::getFacadeRoot()];
        $broadcast = $this->app->bound(BroadcastManager::class) ? $this->app->make(BroadcastManager::class) : null;
        $broadcastDriver = $broadcast?->getDefaultDriver();
        $cacheDefault = config('cache.default');
        $artifacts = sys_get_temp_dir().'/beam-ux-rehearsal-'.Str::random(12);
        $connections = array_values(array_unique([
            (new BeamUxEntry)->getConnectionName() ?? config('database.default'),
            (new BeamUxRelease)->getConnectionName() ?? config('database.default'),
        ]));

        $this->app->instance(PlacedDiskMirror::class, new PlacedDiskMirror(null));
        $this->app->instance(EntryArtifactStore::class, new EntryArtifactStore(Storage::build(['driver' => 'local', 'root' => $artifacts]), 'artifacts'));
        $this->app->forgetInstance(CompileEntryBody::class);
        config(['cache.default' => 'array']);
        $cache = new CacheManager($this->app);
        $this->app->instance('cache', $cache);
        $this->app->instance('cache.store', $cache->driver('array'));
        Cache::swap($cache);
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $broadcast?->setDefaultDriver('null');
        foreach ($connections as $connection) {
            DB::connection($connection)->beginTransaction();
        }

        try {
            return $work();
        } finally {
            foreach (array_reverse($connections) as $connection) {
                DB::connection($connection)->rollBack();
            }
            Bus::swap($facades['bus']);
            Queue::swap($facades['queue']);
            Mail::swap($facades['mail']);
            Notification::swap($facades['notification']);
            Cache::swap($facades['cache']);
            config(['cache.default' => $cacheDefault]);
            if ($broadcastDriver !== null) {
                $broadcast->setDefaultDriver($broadcastDriver);
            }
            foreach ($saved as $abstract => $instance) {
                $instance === null ? $this->app->forgetInstance($abstract) : $this->app->instance($abstract, $instance);
            }
            // What the rehearsal's writes memoized in this process describes rows that no longer exist.
            $this->app->make(ComponentScope::class)->forget();
            RecordPages::forgetScope();
            (new Filesystem)->deleteDirectory($artifacts);
        }
    }
}
