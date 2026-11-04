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
        {path? : The directory to scan for un-registered body files (omit only with --restamp --sources)}
        {--under= : Public path or entry id of the entry scan-root files hang from (default: the realm root)}
        {--type= : The UxType for files whose directory does not name one (layout|template|page|component|theme)}
        {--only=* : Exact body path relative to the unchanged scan root (repeat for each file)}
        {--expect-plan= : Refuse selected apply unless this native dry-run SHA256 still matches}
        {--dry-run : Report which files are already registered and which would be, and write nothing}
        {--restamp : Repair rows registered before ticket 10 stamped the stored form (dry run unless --apply)}
        {--sources : With --restamp: every declared docs source (beam.docs.sources) instead of one path}
        {--apply : With --restamp: write the re-stamps the dry run lists}';

    protected $description = 'Register on-disk BeamUx bodies (every format) not yet in the DB; infer type+namespace+containment from path; run S9 draft inference at import.';

    public function handle(EntryPathResolver $paths): int
    {
        if ($this->option('restamp') || $this->option('sources') || $this->option('apply')) {
            return $this->restamp();
        }

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
        $only = (array) $this->option('only');
        if ($only !== [] || $this->option('expect-plan') !== null) {
            try {
                if ($only === []) {
                    throw new \InvalidArgumentException('--expect-plan requires --only.');
                }
                if ($this->option('dry-run')) {
                    $plan = $this->laravel->make(RegisterEntriesFromDisk::class)->plan($path, $type, only: $only, under: $under);
                    $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                    return $plan['unresolved'] === [] ? self::SUCCESS : self::FAILURE;
                }
                $result = $this->asSystemWriter(fn () => $this->laravel->make(RegisterEntriesFromDisk::class)->scan(
                    $path, $under, $type, only: $only, expectedPlan: $this->option('expect-plan'),
                ));
            } catch (\InvalidArgumentException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        } elseif ($this->option('dry-run')) {
            return $this->dryRun($path, $type);
        } else {
            $result = $this->asSystemWriter(
                fn () => $this->laravel->make(RegisterEntriesFromDisk::class)->scan($path, $under, $type),
            );
        }

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

        // Kept like an edited row, but not an edit: this host could not print the stored body, so it cannot tell whether the
        // row is pristine (registry-docs ticket 16). The lens's own diagnostic is the remedy's first clue.
        foreach ($result['unreadable'] ?? [] as $relative => $why) {
            $this->components->twoColumnDetail($relative, '<fg=yellow>not re-asserted: '.\Splicewire\Beam\Ux\Provenance\Reasserter::UNREADABLE." ({$why})</>");
        }

        foreach ($result['filled'] ?? [] as $relative => $columns) {
            $this->components->twoColumnDetail($relative, 'filled: '.self::settingNames($columns));
        }

        $this->components->info(sprintf(
            '%d registered · %d skipped (already present) · %d ignored (not a body format) · %d failed to compile · %d filled page settings%s.',
            count($result['created']),
            count($result['skipped']),
            count($result['ignored']),
            count($result['failed'] ?? []),
            count($result['filled'] ?? []),
            ($unreadable = count($result['unreadable'] ?? [])) > 0 ? " · {$unreadable} not re-asserted (".\Splicewire\Beam\Ux\Provenance\Reasserter::UNREADABLE.')' : '',
        ));

        return ($result['failed'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `--dry-run` (docs-walkthrough DOCS-01): the import's own plan, read-only. Names every file that would register
     * and every file scan() would refuse for want of a `type`; writes nothing.
     */
    private function dryRun(string $path, ?UxType $type): int
    {
        $plan = $this->laravel->make(RegisterEntriesFromDisk::class)->plan($path, $type);

        foreach ($plan['unregistered'] as $relative) {
            $this->components->twoColumnDetail($relative, in_array($relative, $plan['unresolved'], true) ? '<fg=red>would refuse: no type</>' : '<fg=yellow>would register</>');
        }
        $fills = $this->laravel->make(RegisterEntriesFromDisk::class)->plannedFills($path, $type);
        foreach ($fills as $relative => $columns) {
            $this->components->twoColumnDetail($relative, '<fg=yellow>would fill: '.self::settingNames($columns).'</>');
        }
        $this->components->info(sprintf(
            '%d matched · %d would register · %d ignored (not a body format) · %d without a type · %d would fill page settings. Nothing was written.',
            count($plan['matched']),
            count($plan['unregistered']),
            count($plan['ignored']),
            count($plan['unresolved']),
            count($fills),
        ));

        return self::SUCCESS;
    }

    /**
     * `--restamp` (registry-docs ticket 10): the one-time repair for rows `register()` stamped over the raw file while it
     * stored the file's lowering, so they read edited from birth and never re-asserted. {@see RegisteredRowRestamp} holds
     * the four guards; this lists every row it reached with its verdict, and writes only with `--apply`.
     *
     * `--sources` reaches `package:` sources too, by walking `beam.docs.sources` exactly as the docs seed does, and also
     * lists every seeded or imported row no file reached. Placement (ticket 10 item 3): a mode on this command rather
     * than a seed-side loop in laravel-beam-docs, because the repair is one-time and operator-run; a seed step would run
     * it on every seed, forever, and it needs no beam-docs code (the sources are config, read here as
     * {@see \Splicewire\Beam\Ux\Provenance\ProvenanceBackfill} reads the same shape).
     */
    private function restamp(): int
    {
        if (! $this->option('restamp')) {
            $this->components->error('--sources and --apply belong to --restamp.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('path');
        if ($this->option('sources') === ($path !== '')) {
            $this->components->error('--restamp takes either a path or --sources, not both and not neither.');

            return self::FAILURE;
        }
        if ($path !== '' && ! is_dir($path)) {
            $this->components->error("Not a directory: {$path}");

            return self::FAILURE;
        }
        $sources = $path !== '' ? [['path' => $path]] : array_values((array) config('beam.docs.sources', []));
        if ($sources === []) {
            $this->components->error('No docs source is declared (beam.docs.sources).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        // Resolved inside the swap, as the import is (handle() records why).
        $result = $this->asSystemWriter(fn () => $this->laravel->make(\Splicewire\Beam\Ux\Provenance\RegisteredRowRestamp::class)
            ->run($sources, $apply, unreached: $path === ''));

        $would = 0;
        foreach ($result['rows'] as $row) {
            $restamp = $row['verdict'] === \Splicewire\Beam\Ux\Provenance\RegisteredRowRestamp::RESTAMP;
            $would += $restamp ? 1 : 0;
            $this->components->twoColumnDetail(
                "{$row['row']} ({$row['origin']})",
                $restamp ? ($apply ? '<fg=green>re-stamped</>' : '<fg=yellow>would re-stamp</>') : $row['verdict'],
            );
        }
        foreach ($result['missing'] as $declared) {
            $this->components->error("Declared docs source [{$declared}] is missing on disk.");
        }
        $left = count($result['rows']) - $would;
        $pristine = count(array_filter($result['rows'], fn (array $row): bool => $row['verdict'] === 'pristine'));
        // Counted apart (registry-docs ticket 16): an unreadable row is unjudged, never an edit, and never re-stamped.
        $unreadable = count(array_filter($result['rows'], fn (array $row): bool => str_starts_with($row['verdict'], \Splicewire\Beam\Ux\Provenance\RegisteredRowRestamp::UNREADABLE)));
        $unread = $unreadable > 0 ? " · {$unreadable} ".\Splicewire\Beam\Ux\Provenance\RegisteredRowRestamp::UNREADABLE : '';
        $this->components->info($apply
            ? sprintf('%d re-stamped of %d that qualified · %d left alone (%d pristine)%s.', $result['restamped'], $would, $left, $pristine, $unread)
            : sprintf('%d would re-stamp · %d left alone (%d pristine)%s. Nothing was written; pass --apply to write.', $would, $left, $pristine, $unread));

        return $result['missing'] === [] && (! $apply || $result['restamped'] === $would) ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $columns  `seo_description`, … read as the page settings an author knows (`description`, …). */
    private static function settingNames(array $columns): string
    {
        return implode(', ', array_map(fn (string $column): string => preg_replace('/^seo_/', '', $column), $columns));
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
