<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Support\Facades\Storage;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Storage\DiskStorageDriver;
use Splicewire\Beam\Storage\GitRepoRegistrar;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Storage\StackedStorageDriver;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Storage\MirrorGitStatus;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresStorage
{
    /**
     * The {@see StorageDriverResolver} — beam-ux as the SECOND consumer of the free-beam-core
     * {@see StorageDriver} seam (generalized from `BeamSchemaRegistry`). The
     * default driver is `Stacked(Particle-primary, Disk-mirror)` (charter): the DB particle is
     * source-of-record, a filesystem disk a materialized projection. The mirror disk is configurable
     * (`beam.ux.storage.disk`, default the framework default disk); a host maps `namespace → driver` for
     * alternate backings.
     */
    #[Chained('register', order: 60)]
    protected function registerStorage(): void
    {
        $this->app->singleton(StorageDriverResolver::class, function ($app) {
            // Resolved PER WRITE, not captured: this resolver is a singleton, and a captured writer
            // pins whichever WriteGate was bound when it was first resolved. `AsSystemWriter` rebinds
            // that gate for the duration of a console flow, so a pinned writer made
            // `splicewire:beam:seed` fail with "the write gate refused a write" on any host that had
            // touched this singleton earlier in the same process.
            $particle = new ParticleStorageDriver(fn () => $app->make(ParticleWriter::class));
            $disk = Storage::disk(config('beam.ux.storage.disk'));

            $resolver = (new StorageDriverResolver)
                ->register(StorageDriverResolver::DEFAULT, new StackedStorageDriver(
                    $particle,
                    new DiskStorageDriver($disk),
                ))
                ->register('particle', $particle);

            $map = config('beam.ux.storage.namespaces', []);
            if (is_array($map) && $map !== []) {
                $resolver->mapNamespaces($map);
            }

            return $resolver;
        });

        // The placement-keyed disk mirror — the outbound projection that lands a git-trackable file at the
        // entry's FilePlacement path on Publish (charter S2 / ADR-0165). Its own `beam.ux.storage.mirror_disk`
        // key (DISTINCT from the default Stacked driver's `storage.disk`, which keys by particle uuid): the
        // mirror is the human/git-facing projection and a host opts into it by naming a git-tracked disk.
        // Unset ⇒ a null (no-op) mirror so an un-opted host never grows a disk write.
        $this->app->singleton(PlacedDiskMirror::class, function ($app) {
            $name = config('beam.ux.storage.mirror_disk');
            $disk = ($name === null || $name === '') ? null : Storage::disk($name);

            return new PlacedDiskMirror($disk);
        });

        // Same disk, same degrade-not-fabricate null-when-unconfigured shape as PlacedDiskMirror
        // above — the git-state READ half of what that class WRITES. The actual git shelling is
        // GitRepoRegistrar's (mirror-status-ui ticket 02, now beam-core — its own singleton binding
        // lives there since any beam-core consumer wants the shared in-process cache, not just ux).
        $this->app->singleton(MirrorGitStatus::class, function ($app) {
            $name = config('beam.ux.storage.mirror_disk');
            $disk = ($name === null || $name === '') ? null : Storage::disk($name);

            return new MirrorGitStatus($disk, $app->make(GitRepoRegistrar::class));
        });
    }
}
