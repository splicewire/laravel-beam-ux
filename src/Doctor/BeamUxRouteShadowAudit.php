<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Throwable;

/**
 * The standing operator check on a URL an entry owns that **another route serves first**.
 *
 * ADR-0209 §2 mounts `Route::beamUxSite()` as the last line of `web.php` precisely so the renderer's
 * catch-all shadows nothing the host already claimed. That ordering is correct and stays — but it has an
 * inverse nobody was watching: every route registered *before* it wins, including routes the host never
 * wrote. A package that mounts a route at a URL the entries table also addresses takes the page, and the
 * entry beneath it is simply never reached.
 *
 * **Measured, not hypothetical** (beam-docs-satellite ticket 38). On a fresh `laravel-beam-starter`,
 * `knuckleswtf/scribe` — a hard transitive dependency of beam core — mounts its own HTML docs UI at
 * `/docs` from its unpublished defaults (`type` = laravel, `laravel.add_routes` = TRUE). Beam's install
 * seeds the docs subtree at `/docs`. The starter's `/docs` therefore 500'd on a missing `scribe.index`
 * blade view, with a correctly seeded, published, compiled entry sitting behind it. Every other check in
 * this package passed: the row existed, the artifact was current, the chrome resolved, nav projected it.
 * The one thing nothing asked was whether the URL still belonged to the entry.
 *
 * ## Why it asks the router rather than comparing strings
 *
 * Shadowing is a property of Laravel's own matching — registration order, method, domain, and `where`
 * constraints all participate — so the audit hands the real {@see \Illuminate\Routing\RouteCollection}
 * a real GET request for the entry's resolved URL and looks at what comes back. A string comparison
 * against `route:list` would miss parameterised routes (`/{slug}`) that swallow an entry URL without
 * naming it, and `route:list` prints in a different order than the one that serves requests anyway.
 *
 * The renderer identifies itself by the `beamUxRealm` route default the macro stamps on both of its
 * routes, so "the entry won" needs no route-name convention and survives a host renaming the route.
 *
 * ## Two deliberate non-filters
 *
 *  - **Publish state is not consulted.** A shadowed URL is a defect at every marking, and an unpublished
 *    row is exactly the case where an author is about to discover it the expensive way. Consulting the
 *    gate here would also re-open the prefilter question ADR-0212 settled in the other direction.
 *  - **A host that never mounts the renderer is not audited.** With no `Route::beamUxSite()` there is no
 *    entry surface to shadow, and reporting every row would be noise. beam-ux stays headless-installable
 *    (ADR-0209 §2) and this check stays silent on such a host.
 *
 * Advisory rather than blocking: a host may legitimately front an entry URL with its own route (a
 * redirect, a legacy handler), and beam does not get to overrule that. What it does get to do is say so
 * out loud, because nothing else ever will.
 */
class BeamUxRouteShadowAudit implements DoctorAudit
{
    private const CHECK = 'entry URLs are not shadowed by another route';

    public function __construct(
        private Router $router,
        private UrlResolver $urls,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        // Through the model rather than the literal, so the check honours `beam.core.table_prefix` — a
        // retrofit host renames every beam table and a hardcoded name silently reports "absent" there.
        // `beam.facade.table-prefix-bypass` names the four sibling audits that still hold the literal.
        $table = (new BeamUxEntry)->getTable();

        if (! Schema::hasTable($table)) {
            return [Finding::warn(self::CHECK, "{$table} is absent — publish + migrate beam-ux before auditing entry URLs.")];
        }

        if (! $this->rendererMounted()) {
            return [Finding::pass(
                self::CHECK,
                'this host mounts no `Route::beamUxSite()`, so no entry is served over HTTP and nothing can shadow one.',
            )];
        }

        $shadowed = [];

        foreach (BeamUxEntry::query()->where('type', UxType::Page->value)->cursor() as $entry) {
            // A structural node (ADR-0209 §9) has no segment, so no URL resolves to it and there is
            // nothing to shadow. Same blind spot the artifact and nav readers each had to be taught.
            if ($entry->segment === null || $entry->segment === '') {
                continue;
            }

            // A row with NO PARTICLE has no body and never had one — it is a nav POINTER, addressable at
            // a segment some named route already serves, which is exactly what
            // `splicewire:beam:ux:seed-nav` creates. For those rows "another route serves this URL" is
            // not the defect, it IS the design. Fourth reader of this same property after
            // `CompileEntriesCommand` and {@see BeamUxArtifactAudit}, and it was caught the same way both
            // of those were: by running the check on a real starter, where it named three working nav
            // pointers (/dashboard, /settings/profile, /operator) alongside the one genuine collision.
            if ($entry->particle_id === null) {
                continue;
            }

            $url = $this->urls->resolve($entry);

            if ($url === '' || $url === '/') {
                continue;
            }

            $claimant = $this->claimantFor($url);

            if ($claimant === null) {
                $shadowed[] = "{$entry->slug} → {$url} (nothing matches — the renderer's `where` constraint "
                    .'excludes it; check `beam.ux.site.reserved_prefixes`)';

                continue;
            }

            if ($this->isRenderer($claimant)) {
                continue;
            }

            $shadowed[] = "{$entry->slug} → {$url} (served by ".$this->describe($claimant).')';
        }

        if ($shadowed === []) {
            return [Finding::pass(self::CHECK, 'every addressable entry URL resolves to the entry renderer.')];
        }

        return [Finding::warn(
            self::CHECK,
            count($shadowed).' entry URL(s) are served by something other than the entry renderer, so the '
            .'entry behind them is never reached — and the page that appears is whatever the other route '
            .'returns, up to and including a 500. Compare with `php artisan route:list --path=<url>`: '
            .$this->sample($shadowed).'.',
        )];
    }

    /** Whether the host has mounted the public renderer at all — identified by the macro's route default. */
    private function rendererMounted(): bool
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($this->isRenderer($route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The route Laravel would actually run for a GET of this URL, or null if none matches. Uses the live
     * collection so registration order, constraints and domains all count — which is the whole question.
     */
    private function claimantFor(string $url): ?Route
    {
        try {
            return $this->router->getRoutes()->match(Request::create($url, 'GET'));
        } catch (Throwable) {
            // NotFound (nothing matches) and MethodNotAllowed (something claims the URL on another verb)
            // are both "the renderer did not get it", and the caller reports them the same way.
            return null;
        }
    }

    private function isRenderer(Route $route): bool
    {
        return array_key_exists('beamUxRealm', $route->defaults);
    }

    private function describe(Route $route): string
    {
        $action = $route->getActionName();
        $name = $route->getName();

        return $name === null ? $action : "{$name} → {$action}";
    }

    /** @param  list<string>  $items */
    private function sample(array $items): string
    {
        $shown = array_slice($items, 0, 5);
        $rest = count($items) - count($shown);

        return implode('; ', $shown).($rest > 0 ? " (+{$rest} more)" : '');
    }
}
