<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `splicewire:beam:ux:pin-node` — write an ABSOLUTE `BEAM_UX_NODE_BINARY` into the host's `.env`.
 *
 * `beam.ux.compile.binary` defaults to a bare `node`, which resolves from `PATH` — and **the two
 * processes that compile do not share one**. The CLI (`splicewire:beam:ux:compile`, a queue worker, a
 * deploy script) inherits a login shell's PATH and finds Node; **php-fpm does not**, and the Herd pool
 * on this machine declares no `env[PATH]` at all. So a browser-driven save shells out to `node`, gets
 * `sh: line 0: exec: node: not found`, and the failure is gone one request later — the next read
 * reports `compileError: null`, because the absence of an artifact and the absence of a compile
 * attempt are the same state (beam-docs-satellite 51).
 *
 * ⚠️ **This is why every compile measurement on this map has been green.** Every one was taken from the
 * CLI, which is the side that works. A passing suite, a green `splicewire:beam:ux:compile` and a green
 * `splicewire:beam:doctor` are all compatible with the defect being untouched — the only instrument
 * that can see it is an HTTP save that then produces a new artifact file on disk.
 *
 * **Resolution happens at install, deliberately, rather than each host hand-setting the value.** The
 * path is machine-specific (`…/Herd/config/nvm/versions/node/v22.13.0/bin/node` here), so a committed
 * value would be wrong for a colleague, a fresh clone, and CI. `.env` is the one file that is
 * machine-local by construction, and this command resolves against the CLI's own PATH — the side that
 * already works — so a fresh host is born correct. `BEAM_UX_NODE_BINARY` remains the override: an
 * operator who has set it keeps it unless `--force`.
 */
class PinNodeCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:pin-node
        {--path= : Host project root (defaults to base_path())}
        {--binary= : Pin this exact path instead of resolving one from the CLI PATH}
        {--dry-run : Print the resolved path without writing .env}
        {--force : Overwrite a BEAM_UX_NODE_BINARY already set to a different value}';

    protected $description = 'Pin BEAM_UX_NODE_BINARY to an absolute node path so entry bodies compile under php-fpm, not only from the CLI.';

    private const KEY = 'BEAM_UX_NODE_BINARY';

    public function handle(): int
    {
        $root = rtrim((string) ($this->option('path') ?: base_path()), '/');
        $envPath = "{$root}/.env";

        if (! is_file($envPath)) {
            $this->warn("splicewire:beam:ux:pin-node — no .env at {$root}; set ".self::KEY.' by hand.');

            return self::SUCCESS;
        }

        $binary = $this->resolveBinary();

        if ($binary === null) {
            // Not a failure: a host that compiles through a bound `EntryBodyCompiler` (a warm build
            // service) needs no Node at all, and an install must not die on this machine's PATH.
            $this->warn('splicewire:beam:ux:pin-node — no `node` on this PATH; leaving '.self::KEY.
                ' unset. Entry bodies will not compile until Node is installed or a compiler is bound.');

            return self::SUCCESS;
        }

        if (($version = $this->versionOf($binary)) === null) {
            // ⚠️ Fatal ONLY when the operator named the path. `--binary=/bad/path` is something its
            // author could have gotten right, so it is an error; a RESOLVED path that does not run is a
            // fact about this host, and the estate's rule is that a host fact is advisory — `install`
            // calls this command and must not be refused by one machine's broken Node.
            if ($this->option('binary')) {
                $this->error("splicewire:beam:ux:pin-node — [{$binary}] did not answer `--version`; refusing to pin it.");

                return self::FAILURE;
            }

            $this->warn("splicewire:beam:ux:pin-node — the resolved [{$binary}] did not answer ".
                '`--version`; leaving '.self::KEY.' unset rather than pinning a path that does not run.');

            return self::SUCCESS;
        }

        $contents = (string) file_get_contents($envPath);
        $current = $this->currentValue($contents);

        if ($current === $binary) {
            $this->info("splicewire:beam:ux:pin-node — already pinned to {$binary} ({$version}).");

            return self::SUCCESS;
        }

        // ⚠️ An incumbent value is only worth protecting if it WORKS. Guarding on presence alone left
        // the ticket's own defect in place at exactly the host that had it: a `.env` already carrying
        // the broken bare `BEAM_UX_NODE_BINARY=node` was warned about and skipped by `beam:install`,
        // which is the one path that was supposed to make a host born correct. A value that cannot
        // answer `--version` is not a choice an operator made; it is the thing being repaired.
        if ($current !== null && ! $this->option('force') && $this->isUsablePin($current)) {
            $this->warn('splicewire:beam:ux:pin-node — '.self::KEY." is already set to a working [{$current}]; ".
                "pass --force to repoint it at [{$binary}].");

            return self::SUCCESS;
        }

        if ($current !== null && ! $this->option('force')) {
            $this->line("  ↳ replacing [{$current}], which does not answer `--version`.");
        }

        if ($this->option('dry-run')) {
            $this->line('splicewire:beam:ux:pin-node — would write '.self::KEY."={$binary} ({$version}) into {$envPath}.");

            return self::SUCCESS;
        }

        file_put_contents($envPath, $this->write($contents, $binary));

        $this->info('splicewire:beam:ux:pin-node — pinned '.self::KEY."={$binary} ({$version}).");
        // ⚠️ Measured 2026-08-31 at `~/Herd/splicewire-app`: an HTTP save compiled with NO php-fpm restart
        // — `.env` is read per request. The one host that needs a step is a config-CACHED one, where the
        // value is frozen into `bootstrap/cache/config.php` and nothing re-reads `.env` at all.
        $this->line('  ↳ a config-cached host must re-run `config:cache`; otherwise .env is read per request and this is already live.');

        return self::SUCCESS;
    }

    /**
     * An explicit `--binary` wins; otherwise resolve against THIS process's PATH, which is the CLI's —
     * the side that already works, and the whole reason the value is worth capturing.
     */
    private function resolveBinary(): ?string
    {
        if (is_string($explicit = $this->option('binary')) && $explicit !== '') {
            return $explicit;
        }

        return (new ExecutableFinder)->find('node') ?: null;
    }

    /**
     * Whether an incumbent `.env` value is a pin worth protecting.
     *
     * ⚠️ **`--version` alone cannot answer this, and getting that wrong reproduced the whole ticket.**
     * The first version of this guard asked only whether the incumbent runs — and a bare `node` RUNS,
     * from the CLI, which is the side that has a PATH. So the one value this command exists to
     * replace passed the check that was supposed to catch it, and the test written for it failed. The
     * property that matters is not "does it run here" but "is it a path php-fpm can resolve without a
     * PATH", i.e. absolute — so that is what is asked first.
     */
    private function isUsablePin(string $current): bool
    {
        return str_starts_with($current, DIRECTORY_SEPARATOR) && $this->versionOf($current) !== null;
    }

    /** A positive witness that the resolved path is a working Node, not merely a string on disk. */
    private function versionOf(string $binary): ?string
    {
        $process = new Process([$binary, '--version'], timeout: 15);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput()) ?: null;
    }

    /** The value already in `.env`, unquoted. Null when the key is absent or empty. */
    private function currentValue(string $contents): ?string
    {
        $index = $this->lineIndex($contents);

        if ($index === null) {
            return null;
        }

        $value = trim(substr(explode("\n", $contents)[$index], strlen(self::KEY) + 1));
        $value = trim($value, '"\'');

        return $value !== '' ? $value : null;
    }

    /**
     * Replace the key's line in place, or append it.
     *
     * ⚠️ **The value is quoted** — a real resolved path on this machine is
     * `/Users/…/Application Support/Herd/…/node`, and an unquoted space makes Dotenv read the value as
     * `/Users/…/Application` with the rest silently dropped, failing exactly like the unpinned default
     * it was meant to repair.
     *
     * ⚠️ **And the splice is line-based, NOT `preg_replace`.** A filesystem path is arbitrary text, and
     * `preg_replace` reads `$1` / `\1` / `\\` in its REPLACEMENT as backreferences — so a path
     * containing `$` was silently rewritten into a different path and pinned. Demonstrated: replacing
     * with `/opt/n$1ode/bin/node` wrote `/opt/node/bin/node`. That is the same near-miss as the
     * unquoted space one line up — a value that looks pinned and is wrong — which is why neither the
     * pattern nor the replacement goes near a regex here.
     */
    private function write(string $contents, string $binary): string
    {
        $line = self::KEY.'="'.$this->escape($binary).'"';
        $lines = explode("\n", $contents);
        $index = $this->lineIndex($contents);

        if ($index !== null) {
            $lines[$index] = $line;

            return implode("\n", $lines);
        }

        return rtrim($contents, "\n")."\n\n# Absolute node path for beam-ux's compile-on-save; php-fpm has no PATH.\n{$line}\n";
    }

    /**
     * Escape a value for a DOUBLE-QUOTED dotenv assignment.
     *
     * ⚠️ Measured by this command's own test: writing a path containing a backslash verbatim inside
     * quotes makes `Dotenv` throw `Encountered an unexpected escape sequence` — the whole `.env`
     * becomes unparseable, which is a worse outcome than the unpinned default. Quoting alone was never
     * sufficient; the value has to survive the parser that reads it back.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /** The 0-based index of the line assigning this key, or null when it is absent. */
    private function lineIndex(string $contents): ?int
    {
        foreach (explode("\n", $contents) as $i => $line) {
            if (str_starts_with(ltrim($line), self::KEY.'=')) {
                return $i;
            }
        }

        return null;
    }
}
