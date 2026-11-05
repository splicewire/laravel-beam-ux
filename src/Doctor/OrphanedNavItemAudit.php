<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Nav\Contracts\NavItemSource;
use Splicewire\Beam\Ux\Nav\NavItem;
use Throwable;

/**
 * Advisory doctor check (a moat-free {@see Finding} from rushing/laravel-doctor): validate every persisted
 * record-leaf nav link ({@see NavItemSource}) against the app's REAL registered routes and report drift. A record
 * link can rot in a way a content-derived leaf cannot — an owner can point a {@see NavItem} at a path that no route
 * serves (a rename, a removed page), producing an "orphaned" nav item. This surfaces that drift.
 *
 * ── Semantics (advisory, NEVER a hard fail) ──
 *   - PASS  when every in-host record link resolves to a registered route path (external links are skipped —
 *           off-host targets are not the app's to validate). Also PASSes when the host persists no record leaf
 *           (the {@see \Splicewire\Beam\Ux\Nav\NoNavItemSource} default) or its storage is absent.
 *   - WARN  when one or more in-host record links resolve to no registered route (the drift signal).
 *
 * ── Plugging into the base doctor ──
 *   A host registers it — ADVISORY — into beam-core's `BeamDoctorManifest` from its own provider, beside the
 *   source it binds, so one `splicewire:beam:doctor` run reports it alongside the rest of the family:
 *
 *     $this->app->make(BeamDoctorManifest::class)->register(package: 'app', audit: OrphanedNavItemAudit::class, gate: false);
 *
 * Packaged from the five starters' `App\Doctor\OrphanedNavItemAudit` (make-surface 01), which had split into two
 * versions that differed only in comments; this keeps the commented one.
 */
class OrphanedNavItemAudit implements DoctorAudit
{
    public const CHECK = 'orphaned nav item';

    public function __construct(
        private Router $router,
        private NavItemSource $source,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [$this->finding()];
    }

    private function finding(): Finding
    {
        try {
            $items = [...$this->source->navItems()];
        } catch (Throwable) {
            // No table / record leaf not opted in — an all-content-derived nav has nothing to orphan.
            return Finding::pass(self::CHECK, 'no record-leaf nav to validate (all-content-derived nav).');
        }

        if ($items === []) {
            return Finding::pass(self::CHECK, 'no record-leaf nav entries — nothing to orphan.');
        }

        $knownPaths = $this->registeredPaths();
        $orphans = [];

        foreach ($items as $item) {
            // Off-host links are not the app's to validate.
            if ($item->external) {
                continue;
            }

            if (! in_array($this->normalize($item->href), $knownPaths, true)) {
                $orphans[] = $item->label.' → '.$item->href;
            }
        }

        if ($orphans === []) {
            return Finding::pass(self::CHECK, 'all record nav links resolve to registered routes.');
        }

        return Finding::warn(
            self::CHECK,
            count($orphans).' record nav link(s) resolve to no registered route: '.implode('; ', $orphans).'.',
        );
    }

    /**
     * @return list<string>
     */
    private function registeredPaths(): array
    {
        // array_values because the framework types getRoutes() as a plain `Route[]` — it does
        // return a list today, but nothing in the contract says so, and this method promises one.
        return array_values(array_map(
            fn ($route): string => $this->normalize($route->uri()),
            $this->router->getRoutes()->getRoutes(),
        ));
    }

    private function normalize(string $path): string
    {
        // parse_url() reports "no path component" as null and "unparseable" as FALSE. Both mean
        // the same thing here — there is no path to lift out — so both fall back to the raw
        // string. Treating only null that way let a malformed owner-authored href (`http://:80`)
        // collapse to '/', which resolves to a real route and hid the orphan this audit exists
        // to report.
        $parsed = parse_url($path, PHP_URL_PATH);

        return '/'.trim(is_string($parsed) ? $parsed : $path, '/');
    }
}
