<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\AsSystemWriter;

/**
 * `php artisan splicewire:beam:ux:register-from-disk {path}` — the operator-run **`register-from-disk`**
 * batch (charter S8). Scans `{path}` for every registered body-format file NOT yet in the DB, creates a
 * {@see BeamUxEntry} for each, infers `type` + `namespace` from the path
 * (reverse of S2's placement), writes the body through the free-beam-core StorageDriver, and runs S9
 * draft-schema inference at import so a freshly-registered `component` arrives editable. Idempotent —
 * a file already registered is skipped.
 *
 * The directory chain also carries **containment**: a file is parented by the file named for its
 * containing directory, so a docs tree imports as a tree rather than as a flat pile of orphans. `--under`
 * names what the scan root itself hangs from, which is how a host imports a subtree beneath a docs root
 * it already seeded.
 *
 * `--type` supplies the **`type` a hand-authored tree cannot state.** `type` is inferred from the
 * directory segment before the filename, which only the mirror's own `DefaultPlacement` output is laid
 * out by — a real docs tree names its directories for its TRACK (`build`, `using`), so `type` has no
 * source at all. The path still wins where it answers, so `--type=page` imports a mixed tree correctly.
 * Without it, a scan containing even one un-typeable file **refuses whole and writes nothing** rather
 * than half-importing a tree (beam-docs-satellite ticket 42).
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `register-from-disk`.
 */
class RegisterFromDiskCommand extends Command
{
    use AsSystemWriter;

    protected $signature = 'splicewire:beam:ux:register-from-disk
        {path : The directory to scan for un-registered body files}
        {--under= : Public path or entry id of the entry scan-root files hang from (default: the realm root)}
        {--type= : The UxType for files whose directory does not name one (layout|template|page|component|theme)}';

    protected $description = 'Register on-disk BeamUx bodies (every format) not yet in the DB; infer type+namespace+containment from path; run S9 draft inference at import.';

    public function handle(EntryPathResolver $paths): int
    {
        $path = (string) $this->argument('path');

        if (! is_dir($path)) {
            $this->components->error("Not a directory: {$path}");

            return self::FAILURE;
        }

        $type = null;

        if (($typeOption = (string) $this->option('type')) !== '') {
            $type = UxType::tryFrom($typeOption);

            if ($type === null) {
                $this->components->error(sprintf(
                    'Not a UxType: [%s]. Legal values are: %s.',
                    $typeOption,
                    implode(', ', array_column(UxType::cases(), 'value')),
                ));

                return self::FAILURE;
            }
        }

        $under = null;

        if (($reference = (string) $this->option('under')) !== '') {
            $under = $this->resolveUnder($paths, $reference);

            if ($under === null) {
                $this->components->error("No entry at [{$reference}] to import beneath.");

                return self::FAILURE;
            }

            $this->components->info("Importing beneath [{$under->title}] ({$under->slug}).");
        }

        // The batch writes every imported body through the ParticleWriter, whose default gate is
        // deny-by-default over an authorization gate a console command has no actor for — so without this
        // an import is refused outright on any host that has not hand-bound something permissive. Same
        // refusal `splicewire:beam:seed` hit on `splicewire/www` (beam-docs-satellite ticket 07); the
        // argument was never about seeding, so the seam is shared rather than restated here.
        //
        // The batch is RESOLVED INSIDE the swap, and that is load-bearing rather than stylistic. Taking it
        // as a `handle()` parameter builds it — and, transitively, the `ParticleWriter` holding a
        // constructor-injected `WriteGate` — before the rebind happens, so the permissive binding lands
        // behind an object graph that already closed over the old gate and the import is refused anyway.
        // A container rebind only reaches what has not been constructed yet.
        $result = $this->asSystemWriter(
            fn () => $this->laravel->make(RegisterEntriesFromDisk::class)->scan($path, $under, $type),
        );

        // The `type` refusal (ticket 42). Nothing was written — the batch enumerates the whole tree before
        // its first write precisely so this can be true — so this reports and leaves, ahead of the
        // per-entry output, rather than mixing a refusal into a partial success summary. Every offending
        // path is named, because "4 of 29" without the four is the same useless shape as the
        // QueryException this replaced.
        if (($result['unresolved'] ?? []) !== []) {
            $this->components->error(sprintf(
                '%d of %d files have no inferrable `type`. The directory before the file must name one of: %s — or pass --type to set it for this scan. Nothing was imported.',
                count($result['unresolved']),
                $result['recognized'] ?? count($result['unresolved']),
                implode(', ', array_column(UxType::cases(), 'value')),
            ));

            foreach ($result['unresolved'] as $relative) {
                $this->components->twoColumnDetail($relative, '<fg=red>no type</>');
            }

            return self::FAILURE;
        }

        foreach ($result['created'] as $entry) {
            $draft = $entry->schema_is_draft ? ' (draft schema)' : '';
            $this->components->info("Registered [{$entry->namespace}].{$entry->slug} as {$entry->type?->value}{$draft}");
        }

        // Compile failures (ADR-0209 §7). The entries ARE registered — the batch imports everything
        // importable — but their pages have no artifact and will 404 until the body is fixed, so this is
        // reported per file and the command exits non-zero rather than claiming a clean import.
        foreach ($result['failed'] ?? [] as $relative => $reason) {
            $this->components->error("{$relative}: {$reason}");
        }

        $this->components->info(sprintf(
            '%d registered · %d skipped (already present) · %d ignored (not a body format) · %d failed to compile.',
            count($result['created']),
            count($result['skipped']),
            count($result['ignored']),
            count($result['failed'] ?? []),
        ));

        return ($result['failed'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Resolve `--under` to the entry the scan root hangs from. A **public path** is the spelling an
     * operator has in front of them (`--under=/beam/docs`); an **entry id** is the unambiguous fallback
     * for a subtree root with no addressable path of its own (a pass-through node).
     */
    private function resolveUnder(EntryPathResolver $paths, string $reference): ?BeamUxEntry
    {
        if (str_starts_with($reference, '/')) {
            $chain = $paths->resolve($reference);

            return $chain === null || $chain === [] ? null : end($chain);
        }

        return BeamUxEntry::query()->whereKey($reference)->first();
    }
}
