<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The standing operator check on the two ways a served page can be quietly broken.
 *
 *  1. **Missing or stale artifact** (ADR-0209 §7). Artifacts are keyed by particle version, so "not
 *     compiled" and "compiled from an older body" are the same absence. With no client-compile fallback
 *     — the invisible regression §7 exists to refuse — an uncompiled page is a hard 404 at read time.
 *     This is the check that turns that into something an operator sees before a reader does, which is
 *     what makes the backfill command load-bearing rather than housekeeping.
 *  2. **Orphaned contribution** (ADR-0210 §6). A contributed docs page is a seed row the SITE owns from
 *     creation, so uninstalling the contributor leaves the page behind: `<ManifestTable>` renders its
 *     "not installed" empty state and the page still 200s, deliberately — silently 404ing a page the
 *     site owns would repeat exactly the invisible-failure class §7 rejected. The cost of that choice
 *     is that nothing else in the system would ever mention it again. This is the other half.
 *
 * Both findings name a condition that looks fine in isolation, which is the whole reason a doctor check
 * rather than an exception is the right shape.
 */
class BeamUxArtifactAudit implements DoctorAudit
{
    /** `<ManifestTable endpoint="…">` in either quoting style, in an mdx or tsx body. */
    private const ENDPOINT_PATTERN = '/endpoint\s*=\s*[{"\']+\s*([^"\'}\s]+)/i';

    public function __construct(
        private CompileEntryBody $compile,
        private Router $router,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $artifacts = 'entry body artifacts (compile-on-save)';
        $orphans = 'contributed docs endpoints';

        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::warn($artifacts, 'beam_ux_entries is absent — publish + migrate beam-ux before auditing entries.')];
        }

        $stale = [];
        $unsupported = [];
        $orphaned = [];

        foreach (BeamUxEntry::query()->where('type', UxType::Page->value)->cursor() as $entry) {
            if ($this->compile->uncompilable($entry)) {
                $unsupported[] = "{$entry->slug} ({$entry->format?->value})";

                continue;
            }

            if (! $this->compile->compilable($entry)) {
                continue;
            }

            // A STRUCTURAL node has no artifact and never will, and that is not a finding. A realm root
            // (ADR-0209 §9) is a `page` row with no `segment`: it heads a containment subtree, no URL
            // resolves to it, and no reader can ask for its body. `CompileEntriesCommand` learned this
            // at beam-docs-satellite ticket 07 — and this audit, reading the same table for the same
            // property, did not. So `splicewire:beam:doctor` reported a BLOCKING error naming the four
            // realm roots on every correctly-seeded host, forever, which is the standing red that
            // teaches people to stop reading the doctor. Second reader, same blind spot.
            //
            // A bodyless page that IS addressable stays an error, because that one really does 404.
            if (! $this->compile->artifacts()->has($entry)) {
                if ($entry->segment === null || $entry->segment === '') {
                    continue;
                }

                $stale[] = (string) $entry->slug;

                continue;
            }

            foreach ($this->endpointsIn($entry) as $endpoint) {
                if (! $this->mounted($endpoint)) {
                    $orphaned[] = "{$entry->slug} → {$endpoint}";
                }
            }
        }

        return [
            $this->artifactFinding($artifacts, $stale, $unsupported),
            $this->orphanFinding($orphans, $orphaned),
        ];
    }

    /**
     * @param  list<string>  $stale
     * @param  list<string>  $unsupported
     */
    private function artifactFinding(string $check, array $stale, array $unsupported): Finding
    {
        if ($stale === [] && $unsupported === []) {
            return Finding::pass($check, 'every routable page has an artifact compiled from its current body.');
        }

        $detail = [];

        if ($stale !== []) {
            $detail[] = count($stale).' page(s) have no current artifact and will 404: '.
                $this->sample($stale).'. Run `splicewire:beam:ux:compile`.';
        }

        if ($unsupported !== []) {
            $detail[] = count($unsupported).' routable page(s) are in a format the bound compiler does not '.
                'handle: '.$this->sample($unsupported).'.';
        }

        return Finding::fail($check, implode(' ', $detail));
    }

    /**
     * @param  list<string>  $orphaned
     */
    private function orphanFinding(string $check, array $orphaned): Finding
    {
        if ($orphaned === []) {
            return Finding::pass($check, 'every contributed endpoint a page names is mounted.');
        }

        return Finding::warn(
            $check,
            count($orphaned).' page(s) name an endpoint nothing mounts — the contributing package is '.
            'likely uninstalled. The page still 200s with an empty state (ADR-0210 §6); remove the seed '.
            'row if that is not what you want: '.$this->sample($orphaned).'.'
        );
    }

    /**
     * The endpoints a page's body points a live-data component at. Reads the source through the same
     * codec round trip the compiler uses, so what is audited is what an author wrote.
     *
     * @return list<string>
     */
    private function endpointsIn(BeamUxEntry $entry): array
    {
        $source = $this->compile->sourceFor($entry);

        if ($source === null || ! preg_match_all(self::ENDPOINT_PATTERN, $source, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $matches[1],
            // Only same-origin absolute paths are ours to verify; an off-site URL or a runtime
            // expression is the host's business, and guessing at it would produce false alarms.
            fn (string $endpoint) => str_starts_with($endpoint, '/'),
        )));
    }

    /** Whether any registered GET route matches this path. */
    private function mounted(string $endpoint): bool
    {
        $path = trim(parse_url($endpoint, PHP_URL_PATH) ?: '', '/');

        foreach ($this->router->getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if (trim($route->uri(), '/') === $path) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $items */
    private function sample(array $items): string
    {
        $shown = array_slice($items, 0, 5);
        $rest = count($items) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " (+{$rest} more)" : '');
    }
}
