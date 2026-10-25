<?php

namespace Splicewire\Beam\Ux\Preview;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Splicewire\Beam\Ux\Bindings\RecordPages;
use Splicewire\Beam\Ux\Data\ReleaseChangesData;
use Splicewire\Beam\Ux\Data\ReleaseData;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Release\ReleaseChanges;
use Splicewire\Beam\Ux\Release\ReleaseReadiness;

/**
 * The Preview bar's data (ADR-0232 d9–d13) for a request inside a Preview, in either mode: what it previews, the way
 * out, View as me, the switch to the same URL on the live site (none for a page the Release adds), and for a Release
 * its changed pages, a few Records to open each Record Page on, and everything that would stop Publish. The packaged
 * renderer and a host's own pages (a starter's home) both ask here. `previewUrl` plus `&path=` opens a path in the
 * Preview from the live site.
 */
final class PreviewBar
{
    public function __construct(private ReleaseChanges $changes, private ReleaseReadiness $readiness, private RecordPages $records) {}

    /**
     * Null outside a Preview.
     *
     * @param  array<string, mixed>  $routes  the site mount's route defaults; a host page outside it gets the configured names
     * @return array<string, mixed>|null
     */
    public function for(Request $request, ?BeamUxEntry $entry = null, array $routes = []): ?array
    {
        $preview = $request->attributes->get('beam.ux.preview');
        $live = ! $preview instanceof PreviewContext;
        $preview = $live ? $request->attributes->get(ResolvePreviewContext::LIVE) : $preview;
        if (! $preview instanceof PreviewContext) {
            return null;
        }
        $name = (string) config('beam.ux.route_name', 'beam.ux.');
        $switch = (string) ($routes['beamUxPreviewSwitchRoute'] ?? $name.'site.preview.switch');
        $release = $preview->grant->release;
        $here = $request->getRequestUri();
        $added = ! $live && $entry !== null && $entry->published_version === null;
        $changes = $release === null ? null : $this->changes->of($release);

        return [
            'label' => $release?->title ?? 'saved version',
            'mode' => $live ? 'live' : 'preview',
            'exitUrl' => route((string) ($routes['beamUxPreviewExitRoute'] ?? $name.'site.preview.exit')),
            'viewAsMeUrl' => $live || PreviewContext::centralViewer($request) === null ? null
                : route((string) ($routes['beamUxPreviewViewerRoute'] ?? $name.'site.preview.view-as-me'), ['path' => $here], false),
            'switchUrl' => $added ? null : route($switch, ['to' => $live ? 'preview' : 'live', 'path' => $here], false),
            'previewUrl' => route($switch, ['to' => 'preview'], false),
            'changes' => $changes?->toArray(),
            'recordChoices' => $changes === null ? [] : $this->recordChoicesFor($changes, $preview, $request, (string) $release->realm),
            'readiness' => $release === null ? null : $this->readiness->report($release)->toArray(),
            'redirects' => $this->redirects($preview),
            // Where the Release's own fixes are pressed (ADR-0232 d11): its page on the operator console, if mounted.
            'releaseHref' => $release === null ? null : ReleaseData::frameHref('beam-ux-release.edit', (string) $release->getKey()),
        ];
    }

    /**
     * ADR-0236 d12 (ADR-0232 d14): each URL the Preview's Release moves or takes down, and where it will go once it
     * publishes; a Take-down with no target leaves its URLs to the uniform 404 (`to` null).
     *
     * @return list<array{from: string, to: ?string, reason: string}>
     */
    private function redirects(PreviewContext $preview): array
    {
        $plan = app(\Splicewire\Beam\Ux\Redirect\ReleaseRedirects::class)->forPreview($preview);

        return [
            ...array_map(fn (array $row) => ['from' => $row['path'], 'to' => $row['to'], 'reason' => $row['reason']], $plan['redirects']),
            ...array_map(fn (array $row) => ['from' => $row['path'], 'to' => null, 'reason' => 'takedown'], $plan['gone']),
        ];
    }

    /**
     * A few Records to open a Record Page on (ADR-0232 d10): those the reading request's viewer may read, labelled by
     * the page's title field, at the page's path with the Record's value in place.
     *
     * @return list<array{label: string, path: string}>
     */
    public function recordChoices(BeamUxEntry $page, string $path, Request $read, string $realm, int $limit = 20): array
    {
        $declaration = $page->getAttribute('record_page');
        $field = is_array($declaration) ? ($declaration['field'] ?? null) : null;
        if (! is_string($field)) {
            return [];
        }
        $choices = [];
        foreach ($this->records->records($page, $read, $realm) as $row) {
            $value = RecordPages::slug(Arr::get($row, 'fields.'.$field));
            if ($value === null) {
                continue;
            }
            $label = isset($declaration['titleField']) ? Arr::get($row, 'fields.'.$declaration['titleField']) : null;
            $choices[] = ['label' => (string) ($label ?? $value), 'path' => str_replace('{'.$field.'}', rawurlencode($value), $path)];
            if (count($choices) >= $limit) {
                break;
            }
        }

        return $choices;
    }

    /** @return array<string, list<array{label: string, path: string}>> by Record Page id, read as the Preview's viewer */
    private function recordChoicesFor(ReleaseChangesData $changes, PreviewContext $preview, Request $request, string $realm): array
    {
        $read = clone $request;
        $read->setUserResolver(fn () => $preview->viewer);
        $choices = [];
        foreach ([...$changes->pages, ...array_merge([], ...array_map(fn ($reach) => $reach->pages, $changes->reach))] as $page) {
            if (! $page->recordPage || $page->path === null || isset($choices[$page->entryId])) {
                continue;
            }
            $entry = BeamUxEntry::on($preview->grant->getConnectionName())->find($page->entryId);
            $entry = $entry === null ? null : $preview->project($entry);
            $choices[$page->entryId] = $entry === null ? [] : $this->recordChoices($entry, $page->path, $read, $realm);
        }

        return $choices;
    }
}
