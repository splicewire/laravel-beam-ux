<?php

namespace Splicewire\Beam\Ux\Concerns;

use Illuminate\Support\Facades\Storage;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * One concern of {@see BeamUxServiceProvider}, contributed to its `register` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresCompile
{
    /**
     * The **compile-on-save** seam (ADR-0209 §7). Three producers — the editor save, the disk batch, the
     * `splicewire:beam:ux:compile` backfill — invoke ONE {@see CompileEntryBody} action over a swappable
     * {@see EntryBodyCompiler}, so "what counts as compilable, and as already-current" has exactly one
     * definition for the doctor check to hold everyone to.
     *
     * The default compiler shells out to Node against the HOST's `node_modules`. That is a real
     * deploy-topology commitment and it is made deliberately: every beam-ux host already needs Node to
     * build assets, and paying the compile where content CHANGES beats paying an MDX-compiler download
     * on every page view. A host with a warm build service binds its own compiler and keeps everything
     * above it.
     */
    #[Chained('register', order: 20)]
    protected function registerCompile(): void
    {
        $this->app->singleton(EntryArtifactStore::class, fn () => new EntryArtifactStore(
            Storage::disk(config('beam.ux.compile.disk')),
            (string) config('beam.ux.compile.root', 'beam-ux/artifacts'),
        ));

        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(
            binary: (string) config('beam.ux.compile.binary', 'node'),
            script: config('beam.ux.compile.script'),
            workingDirectory: base_path(),
            timeout: (float) config('beam.ux.compile.timeout', 60),
        ));

        $this->app->singleton(CompileEntryBody::class, fn ($app) => new CompileEntryBody(
            $app->make(EntryBodyCompiler::class),
            $app->make(EntryArtifactStore::class),
            $app->make(StorageDriverResolver::class),
        ));
    }
}
