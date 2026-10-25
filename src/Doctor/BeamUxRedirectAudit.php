<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRedirect;
use Splicewire\Beam\Ux\Redirect\RedirectMap;
use Splicewire\Beam\Ux\Redirect\RedirectResolver;
use Splicewire\Beam\Ux\Redirect\RedirectService;

/**
 * The standing check on a site's Redirects (ADR-0236 d6, d9 and Consequences). A Redirect that stops answering does so
 * silently: the guest gets the uniform 404, and nothing else would ever mention it. So this names one that dangles (its
 * target deleted, gone, or hidden with nothing further), one that loops or runs past the step bound, one at a URL a page
 * answers (a page always wins, d7), and one a host route registered before the renderer serves first (the question
 * {@see BeamUxRouteShadowAudit} asks of entry URLs).
 *
 * It takes the renderer's own walk ({@see RedirectResolver::follow()}) as a guest, so what it names is what a guest
 * gets. A target only readers may read is no defect, and a hidden page with a Redirect leading on from its URL is the
 * walk working as d9 says. Advisory: each is a Redirect an author can fix or remove.
 */
final class BeamUxRedirectAudit implements DoctorAudit
{
    public const DANGLING = 'Redirects reach a target that is there';

    public const LOOPS = 'Redirects end within the step bound, without a loop';

    public const PAGE_SHADOWED = 'no page answers a Redirect\'s own URL';

    public const ROUTE_SHADOWED = 'no host route serves a Redirect\'s URL first';

    public function __construct(
        private RedirectResolver $resolver,
        private RedirectService $redirects,
        private BeamUxRouteShadowAudit $routes,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $table = (new BeamUxRedirect)->getTable();
        if (! Schema::hasTable($table)) {
            return [Finding::inconclusive(self::DANGLING, "{$table} is absent: publish + migrate beam-ux before auditing Redirects.")];
        }
        $found = [self::DANGLING => [], self::LOOPS => [], self::PAGE_SHADOWED => [], self::ROUTE_SHADOWED => []];
        $mounted = $this->routes->rendererMounted();
        foreach (BeamUxRedirect::query()->orderBy('realm')->orderBy('path')->cursor() as $redirect) {
            $label = $redirect->path.($redirect->match === 'prefix' ? ' (and below)' : '')
                .($redirect->realm === BeamUxEntry::REALM_SITE ? '' : " in {$redirect->realm}");
            if ($redirect->match === 'exact' && $this->redirects->answers($redirect->path, $redirect->realm)) {
                $found[self::PAGE_SHADOWED][] = "{$label}: a page answers it, so the Redirect never does";

                continue;
            }
            if ($mounted && ($instead = $this->routes->servedInstead($redirect->path)) !== null) {
                $found[self::ROUTE_SHADOWED][] = "{$label}: served by {$instead}";

                continue;
            }
            $trace = $this->resolver->follow(Request::create($redirect->path), $redirect->path, $redirect->realm, null);
            $via = count($trace['steps']) > 1 ? ' (via '.implode(' → ', $trace['steps']).')' : '';
            match ($trace['end']) {
                'gone', 'dead-end' => $found[self::DANGLING][] = "{$label}: ".$this->why($trace, $redirect->realm).$via,
                'loop' => $found[self::LOOPS][] = "{$label}: loops (".implode(' → ', [...$trace['steps'], $trace['steps'][0]]).')',
                'too-long' => $found[self::LOOPS][] = "{$label}: takes more than ".count($trace['steps']).' steps'.$via,
                default => null,
            };
        }

        return [
            $this->finding(self::DANGLING, $found[self::DANGLING], 'every Redirect reaches a target that is there.',
                'Redirect(s) lead a guest to the uniform 404: '),
            $this->finding(self::LOOPS, $found[self::LOOPS], 'every Redirect ends within '.(int) config('beam.ux.redirects.max_steps', 5).' steps.',
                'Redirect(s) loop or run past `beam.ux.redirects.max_steps`, so a guest gets the uniform 404: '),
            $this->finding(self::PAGE_SHADOWED, $found[self::PAGE_SHADOWED], 'no page answers a Redirect\'s own URL.',
                'Redirect(s) sit at a URL a page answers; the page always wins, so remove them: '),
            $mounted
                ? $this->finding(self::ROUTE_SHADOWED, $found[self::ROUTE_SHADOWED], 'the renderer receives every Redirect\'s URL.',
                    'Redirect(s) sit at a URL another route serves first, so the renderer never answers it: ')
                : Finding::inconclusive(self::ROUTE_SHADOWED, 'this host mounts no `Route::beamUxSite()`, so no Redirect is served over HTTP.'),
        ];
    }

    /** Why the walk found nothing there: the last Redirect it took names a target that is gone or hidden. */
    private function why(array $trace, string $realm): string
    {
        $last = RedirectMap::for($realm, (new BeamUxRedirect)->getConnectionName())->match((string) end($trace['steps']));
        if ($last?->target_kind === 'record') {
            return "its Record {$last->target_collection}:{$last->target_record_id} isn't reached by a guest (no Record Page here "
                ."shows {$last->target_collection}, or the Record is gone or for readers only)";
        }
        if ($last?->target_kind === 'page' && $trace['end'] === 'gone') {
            $page = BeamUxEntry::withTrashed()->find($last->target_entry_id);

            return $page === null ? 'its page no longer exists'
                : "its page {$page->slug} was deleted; restoring the page brings the Redirect back";
        }

        return 'it ends at a hidden page, and no Redirect leads on from there';
    }

    /** @param  list<string>  $items */
    private function finding(string $check, array $items, string $pass, string $warn): Finding
    {
        if ($items === []) {
            return Finding::pass($check, $pass);
        }
        $shown = array_slice($items, 0, 5);
        $rest = count($items) - count($shown);

        return Finding::warn($check, count($items).' '.$warn.implode('; ', $shown).($rest > 0 ? " (+{$rest} more)" : '').'.');
    }
}
