<?php

namespace Splicewire\Beam\Ux\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Disk\RawMdxReader;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Disk\UpdateFromNewer;
use Splicewire\Beam\Ux\Inference\InferDraftSchema;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresDisk
{
    /**
     * The **explicit-operator-batch** disk seam (charter S8, `beamux-build/issues/05`). Binds the
     * format-aware {@see RegisterFromDisk} recognizer/path-envelope deriver + the two batch operations —
     * {@see RegisterEntriesFromDisk} (scan → create → S9-infer-at-import) and {@see UpdateFromNewer}
     * (config-gated, OFF by default). There is deliberately NO ambient filesystem watcher: every inbound
     * disk→DB flow is one of these operator-run batches. Composition seam (ADR-0092): the batch
     * orchestration over the storage port is beam-ux's; the particle records the body rides are beam-core's.
     */
    #[Chained('register', order: 100)]
    protected function registerDisk(): void
    {
        $this->app->singleton(RegisterFromDisk::class);

        // The importer takes the compile action explicitly (ADR-0209 §7's second producer) — a batch
        // that registers pages without compiling them would leave every one of them 404ing until
        // someone ran the backfill.
        $this->app->singleton(RegisterEntriesFromDisk::class, fn ($app) => new RegisterEntriesFromDisk(
            $app->make(RegisterFromDisk::class),
            $app->make(StorageDriverResolver::class),
            $app->make(InferDraftSchema::class),
            $app->make(EntryAccessGate::class),
            $app->make(CompileEntryBody::class),
        ));
        $this->app->singleton(UpdateFromNewer::class);

        // The frontmatter-stripped raw-`.mdx` reader — seeds an mdxeditor buffer with the existing copy
        // (the vite `@mdx-js` plugin compiles `.mdx`, so the client can't `?raw`-load the source). Root
        // config-driven (`beam.ux.content_path`); a missing file degrades to null.
        $this->app->singleton(RawMdxReader::class, function () {
            return new RawMdxReader(
                root: (string) config('beam.ux.content_path', 'resources/js/content'),
            );
        });
    }
}
