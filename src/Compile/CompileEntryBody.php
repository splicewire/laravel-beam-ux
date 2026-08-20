<?php

namespace Splicewire\Beam\Ux\Compile;

use Splicewire\Beam\Ux\Console\CompileEntriesCommand;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **The one shared compile action** ADR-0209 §7 specifies, invoked by all three producers:
 *
 *  - {@see BeamUxEntryBodyController::update()} — the editor save path, which already mirrors to disk
 *    and is therefore the natural hook;
 *  - {@see RegisterEntriesFromDisk} — the operator batch, where Node is trivially available;
 *  - {@see CompileEntriesCommand} — the `splicewire:beam:ux:compile` backfill.
 *
 * One action rather than three call sites of a compiler, because the *policy* — which entries are
 * compilable, what counts as already-current, what happens when compilation fails — has to be identical
 * in all three or the doctor check that reports staleness is checking a rule only some producers follow.
 *
 * **Compilable means a `page` whose format the bound compiler handles.** `page` is the sole routable
 * type (ADR-0209 §6), so it is the only type whose body is ever fetched by a reader; compiling themes
 * and components would spend Node on bodies nothing streams. A page in a format the compiler does not
 * handle is reported by the doctor rather than silently passed over — see {@see uncompilable()}.
 *
 * **Nothing here degrades.** A failure propagates as {@see CompilationFailed} to whichever producer
 * called: a save 500s, a batch reports the file, a backfill exits non-zero. That is the whole content of
 * "no silent client-compile fallback" at the level where it could actually be violated.
 */
class CompileEntryBody
{
    public function __construct(
        private EntryBodyCompiler $compiler,
        private EntryArtifactStore $artifacts,
        private StorageDriverResolver $drivers,
    ) {}

    /**
     * Compile one entry and store the artifact for its current version. Returns the artifact path, or
     * null when the entry is not compilable at all (not a page, or a format this compiler does not
     * handle) — a no-op the producers can call unconditionally.
     *
     * `$source` is passed by producers that already hold it (a save, an import) so the body is not read
     * back out of the store it was just written to; omitted, it is decoded from the particle.
     *
     * Already-current artifacts are skipped unless `$force`: the store's key IS the version, so
     * "already compiled" and "compiled from this exact body" are the same question (see
     * {@see EntryArtifactStore}).
     *
     * @throws CompilationFailed
     */
    public function forEntry(BeamUxEntry $entry, ?string $source = null, bool $force = false): ?string
    {
        if (! $this->compilable($entry)) {
            return null;
        }

        if (! $force && $this->artifacts->has($entry)) {
            return $this->artifacts->path($entry);
        }

        $source ??= $this->sourceFor($entry);

        if ($source === null) {
            throw CompilationFailed::for($entry, 'it has no body to compile.');
        }

        return $this->artifacts->put($entry, $this->compiler->compile($entry, $source));
    }

    /** Whether this entry is one the action compiles at all. */
    public function compilable(BeamUxEntry $entry): bool
    {
        return $entry->type === UxType::Page && $this->compiler->handles($entry);
    }

    /**
     * Whether this entry SHOULD be compilable but is not — a routable page in a format the bound
     * compiler cannot handle. Distinct from {@see compilable()} returning false for a component, which
     * is correct and uninteresting; this one is a page that will fail loudly at read time, and is what
     * the doctor reports.
     */
    public function uncompilable(BeamUxEntry $entry): bool
    {
        return $entry->type === UxType::Page && ! $this->compiler->handles($entry);
    }

    /**
     * The entry's raw source, decoded from its particle body by its own format codec (ADR-0164) — the
     * same round trip the editor performs, so the compiler sees exactly what an author wrote.
     */
    public function sourceFor(BeamUxEntry $entry): ?string
    {
        if ($entry->particle_id === null) {
            return null;
        }

        $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

        if ($item === null) {
            return null;
        }

        return $entry->codec()->decode($item->body ?? []);
    }

    public function artifacts(): EntryArtifactStore
    {
        return $this->artifacts;
    }
}
