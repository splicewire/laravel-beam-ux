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
 * ## The printed block works pasted as printed
 * Laravel matches routes in registration order, and the site's `Route::beamUxSite()` registers a `{path}` catch-all
 * that reserves only `api` and `_beam`. So a line registered after it is shadowed: the first fresh-host proof's
 * `/account` reached the catch-all until its routes were reordered by hand (make-surface 02 review, finding 1). A
 * catch-all is therefore declared `last`, and {@see routesFor()} orders the block so every `last` line follows every
 * other surface's and tier's lines.
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

    /** The nav data target: composed from the surface selection rather than copied from one stub. */
    public const NAV = 'resources/beam-ux/nav.yml';

    /** @var array<string, array<string, string>> surface => [host-relative target => absolute stub path] */
    private array $files = [];

    /** @var array<string, list<string>> surface => printed route lines */
    private array $routes = [];

    /** @var array<string, list<string>> surface => printed route lines that must follow every other line (a catch-all) */
    private array $lastRoutes = [];

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
        // The host's registration of its generated resource map with frame (frame-foundation ruling 03 Q9/Q17): a `.ts`
        // module, so a host with skipLibCheck still checks it, and the host's type test imports it by path.
        $stamps->file(self::BASE, 'resources/js/frame-resource-map.ts', $stubs.'/frame-resource-map.ts.stub');

        // The public site: the packaged home page, then the entry renderer's catch-all, printed after every other line.
        $stamps->route('site', "Route::beamUxHome('site/home');");
        $stamps->route('site', "Route::beamUxSite('site/entry');", last: true);
        $stamps->navEntry('site', "home:\n  segment: /\n  title: Home\n  type: page\n  realm: site");

        // The account shell: the packaged account home inside the host's auth group. beam-accounts mounts the
        // settings and team routes itself (`register_routes`). The seat-gate default is REQUIRED, not decoration: the
        // stamped nav puts /account on the tenant rail, and IA invariant I6 refuses a rail route that "declares no
        // resolvable seat gate" — measured on the fresh `laravel new` host proof, where its absence 500'd every
        // signed-in page (`accountNav` is shared on each request). Open to members, as the starters' account routes are.
        $stamps->route('account', "Route::inertia('account', 'account/home')->middleware(['auth', 'verified'])->name('account')->defaults(\\Splicewire\\Beam\\Authorization\\SeatGate::OPEN_TO_MEMBERS, true);");
        $stamps->route('account', "Route::beamRealmConsole('tenant', ['auth', 'verified']);");
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

    /** @param bool $last the line registers a catch-all, so it is printed after every other surface's and tier's lines */
    public function route(string $surface, string $line, bool $last = false): static
    {
        if ($last) {
            $this->lastRoutes[$surface][] = $line;
        } else {
            $this->routes[$surface][] = $line;
        }

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
     * The printed route block, in paste order: each surface's lines in surface order, then every `last` line (the
     * site's catch-all), so the block registers correctly when pasted exactly as printed.
     *
     * @param  list<string>  $surfaces
     * @return list<array{surface: string, last: bool, lines: list<string>}>
     */
    public function routesFor(array $surfaces): array
    {
        $groups = [];

        foreach ([false, true] as $last) {
            foreach ($this->ordered($surfaces) as $surface) {
                $lines = ($last ? $this->lastRoutes : $this->routes)[$surface] ?? [];

                if ($lines !== []) {
                    $groups[] = ['surface' => $surface, 'last' => $last, 'lines' => $lines];
                }
            }
        }

        return $groups;
    }

    /**
     * Every target a stamp of these surfaces owns and the bytes it writes there — the files plus the composed nav.
     * The parity audit compares a recorded file with this for the selection it was recorded under.
     *
     * @param  list<string>  $surfaces
     * @return array<string, string> host-relative target => content, sorted by target
     */
    public function contentsFor(array $surfaces): array
    {
        $contents = [];

        foreach ($this->filesFor($surfaces) as $target => $stub) {
            $contents[$target] = (string) file_get_contents($stub);
        }

        $contents[self::NAV] = $this->navFor($surfaces);
        ksort($contents);

        return $contents;
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
