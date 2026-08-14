<?php

declare(strict_types=1);

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;

/**
 * `splicewire:beam:ux:pnpm-overrides` — make a pnpm host resolve the beam/schemastud JS surfaces.
 *
 * npm leniently resolves a `file:`-linked package's own UNPUBLISHED transitive semver deps; pnpm does
 * not — it hits `ERR_PNPM_FETCH_404` when `@splicewire/beam-mainframe` → `@schemastud/mainframe` →
 * `@schemastud/frame-remote` → `@schemastud/seam` resolve `"*"` against the registry. This command scans
 * the `@splicewire/*`/`@schemastud/*` packages the host already `file:`-links, walks THEIR dependency
 * graph, and writes a `pnpm.overrides` block pinning every unpublished transitive name to its local
 * `file:` path — so `pnpm install` resolves the whole surface set with no registry round-trip.
 *
 * Idempotent + safe: only runs for a pnpm host (a `pnpm-lock.yaml`/`pnpm-workspace.yaml` present), only
 * pins names that resolve to a real local package dir, never touches an npm/yarn host. An override entry
 * a host has already hand-edited to something other than the computed value survives a routine run —
 * `--force` is required to overwrite it, matching `vendor:publish`'s safe-by-default semantics (new pins
 * for names with no existing entry are always added, force or not). Run once after adding the surface
 * packages, or from `splicewire:beam:install`.
 */
class PnpmOverridesCommand extends Command
{
    protected $signature = 'splicewire:beam:ux:pnpm-overrides {--path= : Host project root (defaults to base_path())} {--dry-run : Print the computed overrides without writing} {--force : Overwrite already-present override entries that differ from the computed value}';

    protected $description = 'Write pnpm.overrides pinning the beam/schemastud JS surfaces\' unpublished transitive deps to their local file: paths (pnpm hosts).';

    /** The JS package scopes whose unpublished transitive deps need pinning. */
    private const SCOPES = ['@splicewire/', '@schemastud/'];

    public function handle(): int
    {
        $root = rtrim($this->option('path') ?: base_path(), '/');
        $pkgPath = "{$root}/package.json";

        if (! is_file($pkgPath)) {
            $this->warn("splicewire:beam:ux:pnpm-overrides — no package.json at {$root}; nothing to do.");

            return self::SUCCESS;
        }

        if (! is_file("{$root}/pnpm-lock.yaml") && ! is_file("{$root}/pnpm-workspace.yaml")) {
            $this->info('splicewire:beam:ux:pnpm-overrides — not a pnpm host (no pnpm-lock.yaml / pnpm-workspace.yaml); skipping (npm/yarn resolve these transitively).');

            return self::SUCCESS;
        }

        $pkg = json_decode((string) file_get_contents($pkgPath), true);
        if (! is_array($pkg)) {
            $this->error("splicewire:beam:ux:pnpm-overrides — {$pkgPath} is not valid JSON.");

            return self::FAILURE;
        }

        $directDeps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);

        // The directly-linked surface packages (name => absolute dir), and the index of ALL local
        // packages discoverable from their org roots (name => absolute dir).
        $linked = $this->linkedSurfacePackages($directDeps, $root);
        if ($linked === []) {
            $this->info('splicewire:beam:ux:pnpm-overrides — no file:-linked @splicewire/@schemastud packages; nothing to pin.');

            return self::SUCCESS;
        }
        $index = $this->indexLocalPackages(array_values($linked));

        // BFS the linked packages' dependency graph; every scoped, locally-resolvable name that is NOT
        // already a direct host dep needs an override so pnpm resolves it from disk, not the registry.
        $needed = $this->transitiveScopedDeps($linked, $index);
        $overrides = [];
        foreach ($needed as $name) {
            if (isset($directDeps[$name]) || ! isset($index[$name])) {
                continue;
            }
            $overrides[$name] = 'file:'.$this->relativePath($root, $index[$name]);
        }
        ksort($overrides);

        if ($overrides === []) {
            $this->info('splicewire:beam:ux:pnpm-overrides — every transitive surface dep is already a direct dependency; no overrides needed.');

            return self::SUCCESS;
        }

        $existing = $pkg['pnpm']['overrides'] ?? [];
        $existing = is_array($existing) ? $existing : [];
        // Safe-unless-force: an entry the host already has (hand-edited or from a prior run) wins over the
        // freshly computed value unless --force says otherwise; a name with no existing entry is always
        // added, since there's nothing to protect.
        $merged = $this->option('force')
            ? $overrides + $existing
            : $existing + $overrides;
        ksort($merged);

        if ($this->option('dry-run')) {
            $this->line(json_encode(['pnpm' => ['overrides' => $merged]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (($pkg['pnpm']['overrides'] ?? null) === $merged) {
            $this->info('splicewire:beam:ux:pnpm-overrides — pnpm.overrides already current ('.count($merged).' pins).');

            return self::SUCCESS;
        }

        $pkg['pnpm'] = ($pkg['pnpm'] ?? []) + [];
        $pkg['pnpm']['overrides'] = $merged;
        file_put_contents(
            $pkgPath,
            json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info('splicewire:beam:ux:pnpm-overrides — wrote '.count($merged).' pnpm override(s):');
        foreach ($merged as $name => $spec) {
            $this->line("  {$name} → {$spec}");
        }
        $this->comment('Run `pnpm install` to resolve.');

        return self::SUCCESS;
    }

    /** Directly-linked surface packages: name => absolute dir (only `file:` deps in the target scopes). */
    private function linkedSurfacePackages(array $directDeps, string $root): array
    {
        $out = [];
        foreach ($directDeps as $name => $spec) {
            if (! $this->inScope($name) || ! is_string($spec) || ! str_starts_with($spec, 'file:')) {
                continue;
            }
            $dir = realpath($root.'/'.substr($spec, 5));
            if ($dir !== false && is_file("{$dir}/package.json")) {
                $out[$name] = $dir;
            }
        }

        return $out;
    }

    /** Index every local package discoverable under the org roots of the linked dirs: name => abs dir. */
    private function indexLocalPackages(array $linkedDirs): array
    {
        $roots = [];
        foreach ($linkedDirs as $dir) {
            // …/js/packages/<org>/<pkg> → the org root is dirname twice; scan orgs one level up.
            $orgRoot = dirname($dir, 2); // …/js/packages
            $roots[$orgRoot] = true;
        }

        $index = [];
        foreach (array_keys($roots) as $orgRoot) {
            foreach (glob("{$orgRoot}/*/*/package.json") ?: [] as $pj) {
                $data = json_decode((string) file_get_contents($pj), true);
                if (is_array($data) && isset($data['name']) && $this->inScope($data['name'])) {
                    $index[$data['name']] = dirname($pj);
                }
            }
        }

        return $index;
    }

    /** BFS the linked packages' (deps + peerDeps) for every in-scope name reachable. */
    private function transitiveScopedDeps(array $linked, array $index): array
    {
        $seen = [];
        $queue = array_keys($linked);
        while ($queue !== []) {
            $name = array_shift($queue);
            $dir = $linked[$name] ?? $index[$name] ?? null;
            if ($dir === null) {
                continue;
            }
            $data = json_decode((string) file_get_contents("{$dir}/package.json"), true);
            $deps = array_merge($data['dependencies'] ?? [], $data['peerDependencies'] ?? []);
            foreach (array_keys($deps) as $dep) {
                if (! $this->inScope($dep) || isset($seen[$dep])) {
                    continue;
                }
                $seen[$dep] = true;
                $queue[] = $dep;
            }
        }

        return array_keys($seen);
    }

    private function inScope(string $name): bool
    {
        foreach (self::SCOPES as $scope) {
            if (str_starts_with($name, $scope)) {
                return true;
            }
        }

        return false;
    }

    /** A `../`-style relative path from $from (dir) to $to (dir). */
    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));
        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }
}
