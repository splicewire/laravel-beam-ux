<?php

namespace Splicewire\Beam\Ux\Sitemap;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\RedirectTraceData;
use Splicewire\Beam\Ux\Data\SiteMapFileData;
use Splicewire\Beam\Ux\Data\SiteMapInboundData;
use Splicewire\Beam\Ux\Data\SiteMapRedirectsData;
use Splicewire\Beam\Ux\Data\SiteMapRowData;
use Splicewire\Beam\Ux\Data\SiteMapSearchData;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;
use Splicewire\Beam\Ux\Manifest\EntryResidency;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Redirect\RedirectTraces;

/**
 * Builds the Site map's rows ({@see SiteMapRowData}). The site-wide parts — the containment snapshot the sitemap verdict
 * reads, and one guest walk per Redirect — are computed once per request (a scoped binding) and shared by every row the
 * list projects; only the page's own publication pins and mirror file are read per row.
 */
final class SiteMapIndex
{
    private ?EntryPathResolver $resolver = null;

    /** @var array<string, list<RedirectTraceData>>|null */
    private ?array $inbound = null;

    public function __construct(
        private SitemapEligibility $eligibility,
        private RedirectTraces $traces,
        private EntryPublication $publication,
        private EntryResidency $residency,
    ) {}

    public function row(BeamUxEntry $entry, ?Authenticatable $reader): SiteMapRowData
    {
        $verdict = $this->eligibility->verdict($entry, $this->resolver());
        $state = $this->publication->state($entry);

        return new SiteMapRowData(
            id: (string) $entry->getKey(),
            title: $entry->title,
            slug: (string) $entry->slug,
            parentId: $entry->parent_id === null ? null : (string) $entry->parent_id,
            segment: $entry->segment,
            navOrder: $entry->nav_order === null ? null : (int) $entry->nav_order,
            url: $entry->isReachableIn(BeamUxEntry::REALM_SITE) && $this->resolver()->ancestry($entry) !== null ? $entry->url() : null,
            publishedReadable: $state->publishedReadable,
            headReadable: $state->headReadable,
            draftPending: $state->draftPending,
            search: new SiteMapSearchData($verdict['listed'], $verdict['reason'], count($verdict['urls'])),
            file: $this->file($entry, $state->publishedVersion, $reader),
            redirects: $this->redirects((string) $entry->getKey()),
        );
    }

    private function resolver(): EntryPathResolver
    {
        return $this->resolver ??= SitemapEligibility::snapshot()[1];
    }

    /** D-37-d: central-resident rows only, and only for a reader the diagnostics ability admits. */
    private function file(BeamUxEntry $entry, ?string $publishedVersion, ?Authenticatable $reader): ?SiteMapFileData
    {
        if ($reader === null || ! $this->residency->isCentralResident($entry)
            || ! Gate::forUser($reader)->allows(DiagnosticsAbility::NAME)) {
            return null;
        }
        $mirror = MirrorStatusRowData::project($entry);
        [$member, $release, $blocked] = $this->repairSubject($entry, $publishedVersion);

        return new SiteMapFileData($mirror->state, $mirror->path, $mirror->exists, $mirror->lastModifiedAt, $member, $release, $blocked);
    }

    /**
     * Research 37 F11: the file repair ops take a {@see BeamUxReleaseMember}. A page's is the member of the newest
     * published Release holding it, when that member froze the version readers see; the op itself still checks the
     * codec and pins when it runs.
     *
     * @return array{?string, ?string, ?string}
     */
    private function repairSubject(BeamUxEntry $entry, ?string $publishedVersion): array
    {
        $connection = $entry->getConnectionName();
        $releases = (new BeamUxRelease)->getTable();
        $members = (new BeamUxReleaseMember)->getTable();
        if (! Schema::connection($connection)->hasTable($members)) {
            return [null, null, 'no-release'];
        }
        $member = BeamUxReleaseMember::on($connection)
            ->join($releases, "{$releases}.id", '=', "{$members}.release_id")
            ->where("{$members}.entry_id", (string) $entry->getKey())
            ->where("{$releases}.status", 'published')
            ->orderByDesc("{$releases}.updated_at")
            ->select(["{$members}.id", "{$members}.release_id", "{$members}.version_id"])
            ->first();
        if ($member === null) {
            return [null, null, 'no-release'];
        }
        if ($publishedVersion === null || (string) $member->version_id !== $publishedVersion) {
            return [null, null, 'pin-moved'];
        }

        return [(string) $member->id, (string) $member->release_id, null];
    }

    private function redirects(string $pageId): SiteMapRedirectsData
    {
        $this->inbound ??= collect($this->traces->all())->filter(fn (RedirectTraceData $trace) => $trace->pageId !== null)
            ->groupBy(fn (RedirectTraceData $trace) => $trace->pageId)->map(fn ($group) => $group->values()->all())->all();
        $traces = $this->inbound[$pageId] ?? [];
        usort($traces, fn (RedirectTraceData $a, RedirectTraceData $b) => [$b->hops, $a->path] <=> [$a->hops, $b->path]);
        $worst = null;
        foreach (RedirectTraces::ATTENTION as $end) {
            if ($worst === null && collect($traces)->contains(fn (RedirectTraceData $trace) => $trace->end === $end)) {
                $worst = $end;
            }
        }

        return new SiteMapRedirectsData(
            count: count($traces),
            maxHops: (int) collect($traces)->max('hops'),
            attention: $worst,
            inbound: array_map(fn (RedirectTraceData $trace) => new SiteMapInboundData($trace->id, $trace->path, $trace->match,
                $trace->end, $trace->hops, $trace->attention, $trace->permanent, $trace->origin), $traces),
        );
    }
}
