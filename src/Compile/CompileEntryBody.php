<?php

namespace Splicewire\Beam\Ux\Compile;

use Splicewire\Beam\Ux\Codec\AcceptsJsonDoc;
use Splicewire\Beam\Ux\Codec\JsonDocPrinter;
use Splicewire\Beam\Ux\Codec\JsonDocShape;
use Splicewire\Beam\Ux\Console\CompileEntriesCommand;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * **The one shared compile action** ADR-0209 §7 specifies, invoked by all three producers:
 *
 *  - {@see \Splicewire\Beam\Ux\Particle\EntryBodySaveOp} — the editor save path, which already mirrors
 *    to disk and is therefore the natural hook (it was `BeamUxEntryBodyController::update()` until
 *    ADR-0214 §6 retired that controller);
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
            // No body is no artifact. A cleared document (`[]`) used to compile to a module that
            // exported nothing, and the reader then told every GUEST to run an artisan command over a
            // page whose honest state is "nothing authored yet" — measured 2026-09-12 on beam.test,
            // satellite.test and a fresh tower after the G2 authoring probe restored `/` to `[]`. Any
            // artifact left from an earlier body is retired so the reader sees the unauthored state
            // (and the host's own default page) rather than a stale module.
            $this->artifacts->forget($entry);

            return null;
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
     *
     * ⚠️ **A canvas-authored body is printed as a MODULE, not as the disk mirror's statements.** The two
     * consumers of a JsonDoc's source want irreconcilable things: `PlacedDiskMirror` wants a file
     * `@splicewire/beam-ux/blockdoc`'s `parse()` can read back (bare JSX statements), and the compiler
     * wants something with a `default` export, because `<EntryBody>` imports the artifact and reads
     * `default` off it. `TsxBodyCodec::decode()` serves the first, so this serves the second.
     *
     * Measured on beam.test 2026-09-11 (G2-BEAM-AUTHOR-ENTRY): an owner authored `/`, Save reported
     * "Saved" truthfully, the artifact compiled with no error and was served 200 — and it exported
     * nothing, so the reader rendered "not compiled yet" over a body that had compiled fine. A
     * canvas-authored page had never been renderable, and nothing anywhere failed.
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

        $body = $item->body ?? [];

        if ($body === []) {
            return null; // a bound particle holding an empty document is still "no body yet"
        }

        if ($entry->codec() instanceof AcceptsJsonDoc && JsonDocShape::is($body)) {
            return JsonDocPrinter::printModule($body);
        }

        return $entry->codec()->decode($body);
    }

    /**
     * Whether the entry's bound particle READS and holds an empty document — the state a cleared
     * canvas leaves behind. Distinct from "the particle cannot be read" (a stale id, a driver that
     * has nothing under the key): there the body is unknown, and an existing address must stand.
     */
    public function holdsEmptyDocument(BeamUxEntry $entry): bool
    {
        if ($entry->particle_id === null) {
            return false;
        }

        $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

        return $item !== null && ($item->body ?? []) === [];
    }

    public function artifacts(): EntryArtifactStore
    {
        return $this->artifacts;
    }
}
