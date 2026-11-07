<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Ux\Doctor\ShellStampParityAudit;
use Splicewire\Beam\Ux\Shell\ShellStamps;

/**
 * `splicewire:beam:ux:stamp-shell` — stamps the host-owned shell files for the front-end surfaces a host adopts, prints
 * the route lines and the staff-seeding call, and never edits `routes/` (make-surface ticket 02).
 *
 * ## Copy-once, the `stamp-auth` way
 * An absent file is stamped; a file identical to its stub (trailing whitespace aside) is left untouched; a tailored
 * file is KEPT unless `--force`. `--dry-run` reports the same decisions and writes nothing. So re-running the stamp
 * after a clean stamp reports every file `identical`, and a host's edits survive a re-run.
 *
 * ## The record
 * Each run (not `--dry-run`) records, in `resources/beam-ux/shell-stamp.json`, the surfaces stamped and every file that
 * is byte-for-byte its stub after the run. {@see ShellStampParityAudit} reads it: a recorded file that has since
 * drifted from its stub is reported (never rewritten). A file the host kept tailored is not recorded, because it was
 * never a stamp. The record is sorted and only rewritten when it changes, so an identical re-run writes nothing.
 *
 * ## Staff reach is the PACKAGED seed flow
 * Ticket 02 asked for a packaged entitlement seeder. It exists: beam-accounts' `DemoTeamSeeder` (demo-gated,
 * non-production by default) and the ungated `RolePermissionsSeeder` run through `splicewire:beam:seed`, and
 * `DefaultEntitlementResolver` composes `os.enter` for an operator-realm grant. So the stamp prints the
 * `DatabaseSeeder` call for that flow and its prerequisites rather than minting a second seeder; for a live estate
 * it names the existing grant calls — choosing WHO is staff is the host's decision.
 */
class StampShellCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:stamp-shell
        {--surfaces= : Comma-separated surfaces to stamp: site, account, operator, os, editor (default: config beam.ux.shell.surfaces)}
        {--force : Overwrite a tailored file with its stub}
        {--dry-run : Report what would be stamped, kept or overwritten; write nothing}';

    protected $description = 'Stamp the host-owned shell files for the chosen front-end surfaces, and print their route lines and staff seeding.';

    /** The record the parity audit reads, relative to the host base path. */
    public const RECORD = 'resources/beam-ux/shell-stamp.json';

    /** The nav data target, composed per surface rather than read from one stub. */
    public const NAV = 'resources/beam-ux/nav.yml';

    /** @var array<string, list<string>> surface => composer packages it needs in the host */
    private const PACKAGES = [
        ShellStamps::BASE => ['inertiajs/inertia-laravel', 'splicewire/laravel-beam-ux'],
        'account' => ['splicewire/laravel-beam-accounts'],
        'operator' => ['splicewire/laravel-beam-accounts'],
        'os' => ['splicewire/laravel-beam-accounts'],
    ];

    public function handle(ShellStamps $stamps, Filesystem $files): int
    {
        $surfaces = $this->surfaces();

        if ($surfaces === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $base = $this->laravel->basePath();
        $record = ShellStampParityAudit::readRecord($base.'/'.self::RECORD);
        $matching = [];

        $this->components->info(sprintf('Shell stamp for: %s%s', implode(', ', $surfaces), $dry ? ' (dry run — nothing is written)' : ''));

        foreach ($this->expected($stamps, $surfaces) as $target => $content) {
            $path = $base.'/'.$target;

            if (! $files->exists($path)) {
                $status = $dry ? 'would stamp' : 'stamped';
            } elseif (rtrim((string) $files->get($path)) === rtrim($content)) {
                $status = 'identical';
            } elseif ($force) {
                $status = $dry ? 'would overwrite' : 'overwritten';
            } else {
                $this->components->twoColumnDetail($target, '<fg=yellow>kept</> tailored — --force to overwrite');

                continue;
            }

            if (! $dry && $status !== 'identical') {
                $files->ensureDirectoryExists(dirname($path));
                $files->put($path, $content);
            }

            $matching[] = $target;
            $this->components->twoColumnDetail($target, $status === 'identical' ? '<fg=gray>identical</>' : "<fg=green>{$status}</>");
        }

        if (! $dry) {
            $this->writeRecord($files, $base, $record, $surfaces, $matching);
        }

        $this->warnMissingPackages($base, $surfaces);
        $this->printRoutes($stamps, $surfaces);
        $this->printSeeding($surfaces);

        return self::SUCCESS;
    }

    /**
     * Every target this stamp owns and the bytes it should hold.
     *
     * @param  list<string>  $surfaces
     * @return array<string, string>
     */
    private function expected(ShellStamps $stamps, array $surfaces): array
    {
        $expected = [];

        foreach ($stamps->filesFor($surfaces) as $target => $stub) {
            $expected[$target] = (string) file_get_contents($stub);
        }

        $expected[self::NAV] = $stamps->navFor($surfaces);
        ksort($expected);

        return $expected;
    }

    /** @return list<string>|null */
    private function surfaces(): ?array
    {
        $option = $this->option('surfaces');
        $configured = config('beam.ux.shell.surfaces', ['site', 'account', 'operator']);
        $raw = is_string($option) && $option !== ''
            ? explode(',', $option)
            : (is_array($configured) ? $configured : []);
        $surfaces = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $raw), fn ($s) => $s !== '')));
        $unknown = array_diff($surfaces, ShellStamps::SURFACES);

        if ($surfaces === [] || $unknown !== []) {
            $this->components->error(sprintf(
                'Unknown or empty --surfaces [%s]. Choose from: %s.',
                implode(',', $unknown ?: $surfaces),
                implode(', ', ShellStamps::SURFACES),
            ));

            return null;
        }

        return array_values(array_filter(ShellStamps::SURFACES, fn ($s) => in_array($s, $surfaces, true)));
    }

    /**
     * @param  array{surfaces: list<string>, files: list<string>}  $previous
     * @param  list<string>  $surfaces
     * @param  list<string>  $matching
     */
    private function writeRecord(Filesystem $files, string $base, array $previous, array $surfaces, array $matching): void
    {
        $allSurfaces = array_values(array_filter(
            ShellStamps::SURFACES,
            fn ($s) => in_array($s, $previous['surfaces'], true) || in_array($s, $surfaces, true),
        ));
        $stamped = array_values(array_unique([...$previous['files'], ...$matching]));
        sort($stamped);

        $json = json_encode(['surfaces' => $allSurfaces, 'files' => $stamped], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $path = $base.'/'.self::RECORD;

        if ($files->exists($path) && $files->get($path) === $json) {
            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $json);
        $this->components->twoColumnDetail(self::RECORD, '<fg=green>recorded</> (read by the beam-ux.shell-stamp-parity audit)');
    }

    /** @param list<string> $surfaces */
    private function warnMissingPackages(string $base, array $surfaces): void
    {
        $composer = json_decode((string) @file_get_contents($base.'/composer.json'), true);
        $require = is_array($composer) ? [...array_keys($composer['require'] ?? []), ...array_keys($composer['require-dev'] ?? [])] : [];
        $needed = [];

        foreach ([ShellStamps::BASE, ...$surfaces] as $surface) {
            array_push($needed, ...(self::PACKAGES[$surface] ?? []));
        }

        foreach (array_unique($needed) as $package) {
            if (! in_array($package, $require, true)) {
                $this->components->warn("The host's composer.json does not require {$package}; the stamped shell needs it.");
            }
        }
    }

    /** @param list<string> $surfaces */
    private function printRoutes(ShellStamps $stamps, array $surfaces): void
    {
        $routes = $stamps->routesFor($surfaces);

        if ($routes === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Mount it — the stamp declares, the host routes (paste into routes/web.php; nothing was edited):');

        foreach ($routes as $surface => $lines) {
            $this->line("    // {$surface}");

            foreach ($lines as $line) {
                $this->line('    '.$line);
            }
        }
    }

    /** @param list<string> $surfaces */
    private function printSeeding(array $surfaces): void
    {
        if (array_intersect($surfaces, ['account', 'operator', 'os']) === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Seed staff reach — call the packaged seed flow from database/seeders/DatabaseSeeder.php run():');
        $this->line("    \$this->command->call('splicewire:beam:seed', \$this->command->option('force') ? ['--force' => true] : []);");
        $this->components->bulletList([
            'It runs beam-accounts\' RolePermissionsSeeder (ungated) and DemoTeamSeeder: an Owner and an Admin on "Demo Team" '
                .'with operator/authoring realm reach (os.enter composes from the operator grant); the Member is denied /operator.',
            'Prerequisites: beam.accounts.demo.seed_users (null = on outside production) and the teams estate migrated '
                .'(beam-accounts estate not declared absent). The demo principals are BeamDemo users, never production staff.',
            'On a live estate grant your OWN staff team instead — Splicewire\Beam\Accounts\Teams\RealmReachGrant::toTeam($team), '
                .'or TeamProvisioner::personalTeamWithFullReachFor($user). Which team or user is staff is the host\'s decision.',
        ]);
    }
}
