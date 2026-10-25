<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Ux\Containment\PreviewEntryPathResolver;
use Splicewire\Beam\Ux\Http\Middleware\ResolvePreviewContext;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Preview\PreviewGrantService;
use Splicewire\Beam\Ux\Release\ReleaseLifecycle;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Release page's Preview (ADR-0232 d15): a signed-in author opens a Release's Preview without a link. It issues
 * the same Release-scoped grant a version preview's link does, for 8 hours, on the member it lands on: the first page
 * the Release changes, else its first saved member. A Release with nothing saved to render (only Take-downs) has none.
 */
final class PreviewReleaseController
{
    public function __construct(private PreviewGrantService $grants, private PreviewEntryPathResolver $paths) {}

    public function __invoke(Request $request, string $release): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('ux.author'), Response::HTTP_FORBIDDEN);
        $found = BeamUxRelease::query()->find($release);
        abort_if($found === null || ! in_array($found->status, ReleaseLifecycle::OPEN, true), Response::HTTP_NOT_FOUND);
        $members = $found->members()->whereNotNull('version_id')->orderBy('entry_id')->get();
        abort_if($members->isEmpty(), Response::HTTP_NOT_FOUND);

        $realm = (string) ($request->route()?->defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        $land = $members->first();
        $grant = $this->grants->issue((string) $land->entry_id, (string) $land->version_id, (string) $found->getKey(),
            (string) $request->user()->getAuthIdentifier(), now()->addHours(8));
        $path = '/';
        foreach ($members as $member) {
            if (($resolved = $this->paths->pathForEntry((string) $member->entry_id, $realm, new PreviewContext($grant->record))) !== null) {
                $path = $resolved;
                break;
            }
        }

        return redirect($path)
            ->withCookie(cookie(ResolvePreviewContext::COOKIE, $grant->token, 480, '/', null, $request->isSecure(), true, false, 'lax'))
            ->withCookie(cookie()->forget(ResolvePreviewContext::VIEWER_COOKIE))
            ->withCookie(cookie()->forget(ResolvePreviewContext::MODE_COOKIE))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
