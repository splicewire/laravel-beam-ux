<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Whether the sitemap lists a page, and why not: the ONE predicate {@see EntrySitemapSource} yields from and the Site map
 * (owner-review-20261102 research 37, 37-T1) reports, so the map can never say "in search" for a page the feed omits.
 *
 * The gates, in the order the feed has always applied them:
 *
 *  1. `not-routable` — not a Page, or not a member of the public `site` realm ({@see BeamUxEntry::isReachableIn()});
 *  2. `unpublished` — the {@see EntryPublishGate} port;
 *  3. `not-public` — the {@see EntryEntitlementGate} port;
 *  4. `unplaced` — no ancestry in the containment tree, so no URL;
 *  5. `not-listable` — a guest may not LIST its chain ({@see EntryAccessResolver::canList()}): members-only, or the
 *     unlisted page (traverse denied, access open) that still answers by direct URL;
 *  6. `hidden-ancestor` — a page above it is unpublished, and a hidden page hides the pages under it (ADR-0236 d8);
 *  7. a Record Page expands to one URL per record a guest reads: `record-page` with its URLs, or `no-records`.
 *
 * Otherwise `listed`, with the page's one URL. URLs are site-relative; the feed prefixes its base URL.
 */
final class SitemapEligibility
{
    public const LISTED = 'listed';

    public const RECORD_PAGE = 'record-page';

    /** The columns a verdict reads, intersected with the host's table (an older host lacks some). */
    private const ROUTING = ['id', 'slug', 'namespace', 'type', 'realm', 'realms', 'parent_id', 'segment',
        'access', 'traverse', 'requirements', 'record_page', 'workflow_marking', 'workflow_version',
        'schema_ref', 'format', 'residency_mode'];

    public function __construct(
        private EntryPublishGate $published,
        private EntryEntitlementGate $entitlement,
    ) {}

    /**
     * Every Page row on the entries connection, with a containment snapshot over them, so a whole site is judged from
     * one query.
     *
     * @return array{Collection<int, BeamUxEntry>, EntryPathResolver}
     */
    public static function snapshot(): array
    {
        $columns = array_values(array_intersect(self::ROUTING, Schema::getColumnListing('beam_ux_entries')));
        $all = BeamUxEntry::query()->select($columns)->where('type', UxType::Page->value)->get();

        return [$all, app(EntryPathResolver::class)->snapshot($all)];
    }

    /**
     * @return array{reason: string, listed: bool, urls: list<string>}
     */
    public function verdict(BeamUxEntry $entry, EntryPathResolver $resolver): array
    {
        $no = fn (string $reason): array => ['reason' => $reason, 'listed' => false, 'urls' => []];

        if ($entry->type !== UxType::Page || ! $entry->isReachableIn(BeamUxEntry::REALM_SITE)) {
            return $no('not-routable');
        }
        if (! $this->published->isPublished($entry)) {
            return $no('unpublished');
        }
        if (! $this->entitlement->isPublic($entry)) {
            return $no('not-public');
        }
        $chain = $resolver->ancestry($entry);
        if ($chain === null) {
            return $no('unplaced');
        }
        if (! app(EntryAccessResolver::class)->canList(null, $chain)) {
            return $no('not-listable');
        }
        foreach ($chain as $node) {
            if (! $this->published->isPublished($node)) {
                return $no('hidden-ancestor');
            }
        }

        if (RecordPages::parameter($entry->segment) !== null) {
            $request = Request::create('/');
            $request->setUserResolver(fn () => null);
            $pages = app(RecordPages::class);
            $urls = [];
            foreach ($pages->records($entry, $request, BeamUxEntry::REALM_SITE) as $row) {
                $url = $pages->url($entry, $row);
                $resolved = $url === null ? null : $resolver->resolve($url);
                if ($resolved !== null && end($resolved)->getKey() === $entry->getKey()) {
                    $urls[] = $url;
                }
            }

            return $urls === [] ? $no('no-records') : ['reason' => self::RECORD_PAGE, 'listed' => true, 'urls' => $urls];
        }

        return ['reason' => self::LISTED, 'listed' => true, 'urls' => [$entry->url()]];
    }
}
