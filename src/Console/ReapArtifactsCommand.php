<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `php artisan splicewire:beam:ux:reap-artifacts` — remove the artifact DIRECTORY of an entry that no
 * longer exists (beam-docs-satellite 66).
 *
 * {@see EntryArtifactStore::put()} prunes per entry and per version, so a live entry's directory holds
 * exactly one artifact at its current address. What never happened is directory removal:
 * {@see EntryArtifactStore::forget()} was public with zero callers, so every entry ever hard-deleted
 * left its directory behind forever. `BeamUxEntry`'s `deleted` hook now calls it on a force-delete;
 * this command is the backfill for everything deleted before that landed, and it stays useful
 * afterwards because a `migrate:fresh`, a re-seed or a restored database drops rows without ever
 * firing a model event.
 *
 * ## It PREVIEWS by default
 *
 * Same posture as the `surgeon:*` writers: a report is always safe to run, and `--apply` is a separate,
 * deliberate call. Nothing serves a stale artifact — the address is version-keyed and `has()` checks
 * the current version, so an orphan is unreachable rather than wrong — which makes this hygiene, not a
 * repair, and not worth an accident.
 *
 * ## ⚠️ The disk may be shared with entry sets this command cannot see
 *
 * `beam_ux_entries` is registered into BOTH the central `migrate` and the tenant `tenants:migrate`
 * passes, so on a multi-tenant host the table exists once per schema — while the compile disk is an
 * ordinary Laravel disk that is tenant-scoped only if the host installed a filesystem bootstrapper.
 * Measured 2026-09-03 at `~/Herd/splicewire-app`: 19 schemas carry the table and `config/tenancy.php`
 * registers no `FilesystemTenancyBootstrapper`, so all 19 entry sets address ONE artifact root. Reaping
 * from one connection would then delete every other tenant's artifacts and pass a naive before/after
 * count exactly as well as a correct run.
 *
 * Whether that is true is a fact about the HOST, so this command does not guess and does not throw over
 * it (`AGENTS.md`: a check whose answer depends on the host is advisory). It states the risk on every
 * run, names the connection whose entries it read, and leaves the operator to run it once per scope —
 * or to decline. The count it reports is always "orphaned **with respect to this connection**", never
 * "orphaned".
 */
class ReapArtifactsCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:reap-artifacts
        {--apply : Actually delete. Without it the command only reports.}';

    protected $description = 'Report (or --apply) removal of artifact directories whose entry no longer exists.';

    public function handle(EntryArtifactStore $artifacts): int
    {
        // The same two config values `WiresCompile` hands the store when it binds it. The store keeps
        // its root private and exposes only `disk()`, so this reads the key rather than the object; the
        // default here and there must stay the same string.
        $root = (string) config('beam.ux.compile.root', 'beam-ux/artifacts');
        $disk = $artifacts->disk();

        // Both readings in the same breath, and the directory list FIRST: `storage/app` is shared with
        // any concurrent session compiling entries, so a directory created between the two reads must
        // land outside the snapshot rather than inside it and be mistaken for an orphan.
        $directories = array_map(basename(...), $disk->directories($root));
        $live = BeamUxEntry::withTrashed()->pluck('id')->map(strval(...))->all();

        $liveSet = array_flip($live);
        $orphans = array_values(array_filter($directories, fn (string $id) => ! isset($liveSet[$id])));
        $kept = count($directories) - count($orphans);

        $connection = BeamUxEntry::query()->getConnection()->getName();

        $this->components->info(sprintf(
            '%d artifact director(ies) under [%s]: %d belong to one of the %d entr(ies) on connection [%s] '.
            '(soft-deleted rows keep theirs — they can be restored), %d are orphaned with respect to it.',
            count($directories),
            $root,
            $kept,
            count($live),
            $connection,
            count($orphans),
        ));

        // Stated on EVERY run, including a clean one: the reading above is scoped to one connection, and
        // a host whose compile disk is not tenant-scoped has other entry sets addressing this same root.
        $this->components->warn(
            'This reading covers only the entries visible on connection ['.$connection.']. If this host '.
            'shares one compile disk across tenants, run it once per scope — every artifact belonging to '.
            'a scope you did not read counts as an orphan here.'
        );

        if ($orphans === []) {
            // "0 orphans" and "the sweep matched nothing" must not read the same, so the reading that
            // distinguishes them is printed with it: live entries still have their directories.
            $this->components->info("nothing to reap; {$kept} live-entry director(ies) present.");

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->components->info('preview only — re-run with --apply to delete them.');

            return self::SUCCESS;
        }

        foreach ($orphans as $id) {
            $disk->deleteDirectory("{$root}/{$id}");
        }

        // Re-read rather than subtract. The live half is what can silently not run: a reap that removed
        // EVERYTHING passes a before/after count exactly as well as a correct one, so the assertion that
        // matters is that the live entries' directories are still there.
        $after = array_map(basename(...), $disk->directories($root));
        $survivors = count(array_filter($after, fn (string $id) => isset($liveSet[$id])));

        $this->components->info(sprintf(
            'reaped %d; %d director(ies) remain, %d of them belonging to a live entry (was %d).',
            count($orphans),
            count($after),
            $survivors,
            $kept,
        ));

        if ($survivors < $kept) {
            $this->components->error('a live entry lost its artifact directory — this run removed more than it named.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
