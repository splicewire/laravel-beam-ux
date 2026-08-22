<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * `php artisan splicewire:beam:ux:compile` — the **backfill** producer of ADR-0209 §7's three.
 *
 * The other two compile at the moment content changes (an editor save, a disk import). This one exists
 * for the cases where content changed without either: a `migrate:fresh` + seed, a restored database, a
 * host that has just installed the renderer over an existing tree, or a compiler upgrade that makes
 * every artifact stale at once. It is load-bearing rather than housekeeping, because §7 removed the
 * fallback that would otherwise paper over a missing artifact.
 *
 * Deliberately **not** run automatically on deploy by beam-ux: it needs Node and a database, and a
 * package deciding when a host's deploy pipeline shells out is exactly the kind of ambient behaviour
 * ADR-0116 keeps host-side. `splicewire:beam:install` and the deploy script call it.
 *
 * A failure is reported per entry and the run continues, then the command exits non-zero: one broken
 * body must not stop the other four hundred from compiling, and the run must still fail the pipeline.
 */
class CompileEntriesCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:compile
        {--realm= : Only entries reachable in this realm}
        {--slug=* : Only these slugs}
        {--force : Recompile even when the artifact is already current}';

    protected $description = 'Compile entry bodies to their ES-module artifacts (ADR-0209 §7 — no client-compile fallback).';

    public function handle(CompileEntryBody $compile): int
    {
        $compiled = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->entries() as $entry) {
            if (! $compile->compilable($entry)) {
                if ($compile->uncompilable($entry)) {
                    // A routable page the bound compiler cannot handle: it will 404 at read time, so
                    // say so now rather than letting the count of "skipped" hide it.
                    $this->components->warn("{$entry->slug}: no compile strategy for format [{$entry->format?->value}].");
                    $failed++;
                }

                continue;
            }

            try {
                $before = $compile->artifacts()->has($entry);
                $compile->forEntry($entry, force: (bool) $this->option('force'));

                if ($before && ! $this->option('force')) {
                    $skipped++;

                    continue;
                }

                $this->components->info("{$entry->slug} → {$compile->artifacts()->path($entry)}");
                $compiled++;
            } catch (CompilationFailed $e) {
                // A STRUCTURAL node with no body is not a failure. A realm root (ADR-0209 §9) is a
                // `page` row with no `segment`: it heads a containment subtree and is never addressed
                // directly, so it has nothing to compile and never will. Counting those as failures
                // meant this command reported `failed 4` on a correctly-seeded fresh install — every
                // install, forever — which is exactly the kind of standing red that teaches people to
                // ignore the output. A bodyless page that IS addressable stays an error, because that
                // one really does 404 at read time.
                if ($this->isStructural($entry)) {
                    $skipped++;

                    continue;
                }

                // A row that has NO PARTICLE has no body and never had one — nothing was written for
                // it, so there is nothing to compile and no compiler could have succeeded. Two shipped
                // things create these deliberately: `splicewire:beam:ux:seed-nav`, whose rows are
                // POINTERS at segments a named Laravel route already serves (`/`, `/dashboard`), and
                // `splicewire:beam:ux:scaffold`, whose rows are empty on purpose pending authoring.
                //
                // The structural-node rule above was written for the realm root and keyed on "no
                // segment", on the reasoning that a bodyless ADDRESSABLE page 404s at read time. That
                // premise does not hold on a host which is not served wholly from entries: a fresh
                // `laravel-beam-starter` seeds five addressable nav pointers whose URLs are claimed by
                // routes registered ABOVE the renderer's catch-all, so they serve correctly and this
                // command reported `failed 5` on every correct install — the same standing red the
                // realm-root fix was written to remove (beam-docs-satellite tickets 07, 11).
                //
                // Reported, not silent, because a bodyless page whose segment ISN'T claimed really will
                // 404 — but a warning, so it cannot fail the exit code of a correct install.
                if ($entry->particle_id === null) {
                    $this->components->warn("{$entry->slug}: no body yet — nothing to compile (a nav pointer or an unauthored scaffold).");
                    $skipped++;

                    continue;
                }

                $this->components->error($e->getMessage());
                $failed++;
            }
        }

        $this->components->info("compiled {$compiled}, already current {$skipped}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A containment node that exists to be walked THROUGH, not served: no `segment`, so no URL resolves
     * to it and no reader can ask for its body. `EntryPathResolver` steps transparently through these.
     */
    private function isStructural(BeamUxEntry $entry): bool
    {
        return $entry->segment === null || $entry->segment === '';
    }

    /** @return iterable<int, BeamUxEntry> */
    private function entries(): iterable
    {
        $query = BeamUxEntry::query()->where('type', UxType::Page->value);

        if (($realm = $this->option('realm')) !== null && $realm !== '') {
            $query->whereJsonContains('realms', $realm);
        }

        /** @var array<int, string> $slugs */
        $slugs = (array) $this->option('slug');

        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        }

        return $query->orderBy('slug')->cursor();
    }
}
