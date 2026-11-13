<?php

namespace Splicewire\Beam\Ux\Analytics;

use Splicewire\Beam\Analytics\Areas\AnalyticsArea;
use Splicewire\Beam\Analytics\Areas\AnalyticsReading;
use Splicewire\Beam\Analytics\Areas\Attributes\Area;
use Splicewire\Beam\Analytics\Data\AnalyticsFigureData;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;

/**
 * Site (analytics-area ticket 10): how the site's pages stand, read from the pages that already report it. How many
 * pages are published, how many the sitemap indexes, and how many are published yet left out of it, from **Sitemap
 * health**; how many **Redirects** stand; and, in Install scope only (the operator's side), the **mirror** state of the
 * site's entries, from **Site health**. Each figure links to the page it comes from.
 *
 * No figure is a second count. Each is a count over the rows the destination's own list returns to this viewer, with
 * that list's own query, projection and filters ({@see SiteDestinations::rows()}), and its filtered list reports the
 * same number:
 *
 *  - Published pages = Sitemap health, `filter[type]=page&filter[published]=true`;
 *  - Indexed pages = Sitemap health, `filter[type]=page&filter[indexed]=true`;
 *  - Sitemap issues = Sitemap health, `filter[type]=page&filter[published]=true&filter[indexed]=false`;
 *  - Redirects = the Redirects list;
 *  - Mirror state = Site health per `filter[state]=…`.
 *
 * A list this viewer may not read here draws no figure. The area's own gate is {@see SiteAreaAbility} (analytics-area 20): in
 * tenant scope the host's tenant site-editing ability, `site.edit`, failing closed where the host does not define it; in install
 * scope beam-ux's diagnostics read ability ({@see DiagnosticsAbility}), the ability Site health and Sitemap health already
 * declare. In tenant scope a reader may pass the area yet read none of those diagnostics pages, and then draws none of their
 * figures.
 *
 * Scaffolded by `splicewire:beam:analytics:make-area SiteArea` (ticket 10), then filled in.
 */
#[Area(key: 'site', label: 'Site', icon: 'Globe', order: 30, ability: SiteAreaAbility::NAME)]
final class SiteArea implements AnalyticsArea
{
    public const SITEMAP_HEALTH = 'beam-ux-sitemap-health';

    public const SITE_HEALTH = 'beam-ux-site-health';

    public const REDIRECTS = 'beam-ux-redirect';

    public function __construct(
        private readonly SiteDestinations $destinations,
    ) {}

    /** @return list<AnalyticsFigureData> */
    public function measure(AnalyticsReading $reading): array
    {
        $figures = [];

        $pages = $this->destinations->rows(self::SITEMAP_HEALTH, ['type' => 'page']);
        if ($pages !== null) {
            $href = $this->destinations->href(self::SITEMAP_HEALTH);
            $published = array_filter($pages, fn (array $row): bool => ($row['published'] ?? false) === true);

            $figures[] = new AnalyticsFigureData(id: 'published', label: 'Published pages', value: count($published), href: $href);
            $figures[] = new AnalyticsFigureData(id: 'indexed', label: 'Indexed pages', value: count(array_filter($pages, fn (array $row): bool => ($row['indexed'] ?? false) === true)), href: $href);
            $figures[] = new AnalyticsFigureData(id: 'sitemap-issues', label: 'Published, not in the sitemap', value: count(array_filter($published, fn (array $row): bool => ($row['indexed'] ?? true) === false)), href: $href);
        }

        $redirects = $this->destinations->rows(self::REDIRECTS);
        if ($redirects !== null) {
            $figures[] = new AnalyticsFigureData(id: 'redirects', label: 'Redirects', value: count($redirects), href: $this->destinations->href(self::REDIRECTS));
        }

        if ($reading->scope->isInstall()) {
            $entries = $this->destinations->rows(self::SITE_HEALTH);
            if ($entries !== null) {
                $figures[] = new AnalyticsFigureData(id: 'mirror', label: 'Mirror state', value: $this->mirror($entries), href: $this->destinations->href(self::SITE_HEALTH));
            }
        }

        return $figures;
    }

    /**
     * Each mirror state with its count, most first ("3 clean, 1 modified"), or "No entries".
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function mirror(array $entries): string
    {
        $counts = array_count_values(array_map(fn (array $row): string => (string) ($row['state'] ?? 'unknown'), $entries));
        if ($counts === []) {
            return 'No entries';
        }

        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return implode(', ', array_map(fn (string $state, int $count): string => "{$count} {$state}", array_keys($counts), $counts));
    }
}
