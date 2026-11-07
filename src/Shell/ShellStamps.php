<?php

namespace Splicewire\Beam\Ux\Shell;

/**
 * The shell stamp catalog — what `splicewire:beam:ux:stamp-shell` writes and prints, per front-end surface
 * (make-surface ticket 02). A stamp is a COPY-ONCE file that then belongs to the host (the `stamp-auth` precedent): it
 * is config-shaped — the Inertia mount, the root view, the theme tokens, the thin shared-prop middleware, the nav data —
 * never machinery. Machinery stays packaged (runbook `references/frontend-surfaces.md`: "a fresh site … writes only
 * config").
 *
 * ## Why 01's residue decides the list
 * Ticket 01 moved every identical starter copy of beam-owned wiring into packages and recorded what each host still
 * owns. Measured at the five T1 starter heads (`slices/02-stamp-shell/proof/inventory-t1-heads.tsv`): the root view and
 * the token CSS are identical ×5, the thin `HandleInertiaRequests` (`use SharesBeamShell`) identical ×4, and the mount,
 * the nav data and the brand config tailored per host. The stubs are those identical copies — minus the optional
 * `laravel-beam-analytics` directives, which a host without that package would render as literal text.
 *
 * ## Surfaces are independent; the base is shared
 * `'*'` is the base every surface needs (the mount, the view, the tokens, the middleware). The rest are route lines
 * PRINTED for the host to paste, never edited into `routes/` — the same rule the generators keep.
 *
 * ## Tiers contribute
 * A tier package adds its own stamp file or route line for a surface through {@see file()} / {@see route()} from its
 * service provider (tower-shell's `Route::towerSpa()` is the named example). Contributions are additive and
 * last-wins by target path, so a tier can replace a base stub with its own.
 */
class ShellStamps
{
    /** The surfaces the runbook defines, in the order the stamp reports them. */
    public const SURFACES = ['site', 'account', 'operator', 'os', 'editor'];

    /** The base every surface stamps. */
    public const BASE = '*';

    /** @var array<string, array<string, string>> surface => [host-relative target => absolute stub path] */
    private array $files = [];

    /** @var array<string, list<string>> surface => printed route lines */
    private array $routes = [];

    /** @var array<string, list<string>> surface => nav.yml fragments (YAML mapping entries) */
    private array $nav = [];

    public static function withDefaults(): self
    {
        $stubs = dirname(__DIR__, 2).'/stubs/shell';
        $stamps = new self;

        $stamps->file(self::BASE, 'app/Http/Middleware/HandleInertiaRequests.php', $stubs.'/HandleInertiaRequests.php.stub');
        $stamps->file(self::BASE, 'resources/views/app.blade.php', $stubs.'/app.blade.php.stub');
        $stamps->file(self::BASE, 'resources/css/app.css', $stubs.'/app.css.stub');
        $stamps->file(self::BASE, 'resources/js/app.tsx', $stubs.'/app.tsx.stub');

        // The public site: the packaged home page, then the entry renderer's catch-all, which must be LAST.
        $stamps->route('site', "Route::inertia('/', 'site/home')->name('home');");
        $stamps->route('site', "Route::beamUxSite('site/entry'); // LAST: its {path} catch-all takes every route registered after it");
        $stamps->navEntry('site', "home:\n  segment: /\n  title: Home\n  type: page\n  realm: site");

        // The account shell: the packaged account home inside the host's auth group. beam-accounts mounts the
        // settings and team routes itself (`register_routes`).
        $stamps->route('account', "Route::inertia('account', 'account/home')->middleware(['auth', 'verified'])->name('account');");
        $stamps->navEntry('account', "account:\n  segment: /account\n  title: Account\n  type: page\n  realm: account");

        // The operator front-end: the realm console macro (its own home, its own manifest).
        $stamps->route('operator', "Route::beamRealmConsole('operator', ['auth', 'verified']);");

        // The desktop shell: the packaged `os` page behind the projected `os.enter` entitlement.
        $stamps->route('os', "Route::inertia('os', 'os')->middleware(['auth', 'verified', 'can:entitlement:os.enter'])->name('os.shell');");

        // The in-place editor has no route of its own: beam-inertia's MainframeHost wraps every realm's pages.
        // Its host input is the component registry the stamped mount passes; nothing more to print.

        return $stamps;
    }

    public function file(string $surface, string $target, string $stub): static
    {
        $this->files[$surface][$target] = $stub;

        return $this;
    }

    public function route(string $surface, string $line): static
    {
        $this->routes[$surface][] = $line;

        return $this;
    }

    public function navEntry(string $surface, string $yaml): static
    {
        $this->nav[$surface][] = $yaml;

        return $this;
    }

    /**
     * The files a stamp of these surfaces writes: the base plus each surface's own, later surfaces and contributions
     * winning a shared target.
     *
     * @param  list<string>  $surfaces
     * @return array<string, string> host-relative target => absolute stub path
     */
    public function filesFor(array $surfaces): array
    {
        $files = $this->files[self::BASE] ?? [];

        foreach ($this->ordered($surfaces) as $surface) {
            $files = ($this->files[$surface] ?? []) + $files;
        }

        return $files;
    }

    /**
     * Every stamp target any surface can write — what the parity audit may compare.
     *
     * @return array<string, string>
     */
    public function allFiles(): array
    {
        return $this->filesFor(self::SURFACES);
    }

    /**
     * @param  list<string>  $surfaces
     * @return array<string, list<string>> surface => route lines, in surface order
     */
    public function routesFor(array $surfaces): array
    {
        $routes = [];

        foreach ($this->ordered($surfaces) as $surface) {
            if (($this->routes[$surface] ?? []) !== []) {
                $routes[$surface] = $this->routes[$surface];
            }
        }

        return $routes;
    }

    /**
     * The nav data for these surfaces: one YAML document, entries in surface order. Deterministic, so a re-stamp with
     * the same surfaces compares identical.
     *
     * @param  list<string>  $surfaces
     */
    public function navFor(array $surfaces): string
    {
        $entries = [];

        foreach ($this->ordered($surfaces) as $surface) {
            array_push($entries, ...($this->nav[$surface] ?? []));
        }

        return "# Stamped by splicewire:beam:ux:stamp-shell — the host's nav data (beam-ux seeds it into nav entries).\n"
            ."# Tailor freely: a re-stamp keeps a tailored copy unless --force.\n\n"
            .implode("\n\n", $entries)."\n";
    }

    /**
     * @param  list<string>  $surfaces
     * @return list<string>
     */
    private function ordered(array $surfaces): array
    {
        return array_values(array_filter(self::SURFACES, fn (string $s) => in_array($s, $surfaces, true)));
    }
}
