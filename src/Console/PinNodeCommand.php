<?php

declare(strict_types=1);

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
            $this->error("splicewire:beam:ux:pin-node — [{$binary}] did not answer `--version`; refusing to pin it.");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($envPath);
        $current = $this->currentValue($contents);

        if ($current === $binary) {
            $this->info("splicewire:beam:ux:pin-node — already pinned to {$binary} ({$version}).");

            return self::SUCCESS;
        }

        if ($current !== null && ! $this->option('force')) {
            $this->warn('splicewire:beam:ux:pin-node — '.self::KEY." is already set to [{$current}]; ".
                "pass --force to repoint it at [{$binary}].");

            return self::SUCCESS;
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
        if (! preg_match('/^'.self::KEY.'=(.*)$/m', $contents, $m)) {
            return null;
        }

        $value = trim($m[1]);
        $value = trim($value, "\"'");

        return $value !== '' ? $value : null;
    }

    /**
     * Replace the key in place, or append it. ⚠️ **The value is quoted** — a real resolved path on this
     * machine is `/Users/…/Application Support/Herd/…/node`, and an unquoted space makes Dotenv read
     * the value as `/Users/…/Application` with the rest silently dropped, which fails exactly like the
     * unpinned default it was meant to repair.
     */
    private function write(string $contents, string $binary): string
    {
        $line = self::KEY.'="'.$binary.'"';

        if (preg_match('/^'.self::KEY.'=.*$/m', $contents)) {
            return (string) preg_replace('/^'.self::KEY.'=.*$/m', $line, $contents);
        }

        return rtrim($contents, "\n")."\n\n# Absolute node path for beam-ux's compile-on-save; php-fpm has no PATH.\n{$line}\n";
    }
}
