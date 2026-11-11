<?php

namespace Splicewire\Beam\Ux\Http\Controllers;

use Illuminate\Http\Request;
use LogicException;
use Splicewire\Beam\Ux\Data\Pages\EntryPageData;
use Splicewire\Beam\Ux\Head\PageHead;
use Splicewire\Beam\Ux\Head\PageHeadComposer;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Intake\IntakeForms;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewBar;
use Splicewire\Beam\Ux\Preview\PreviewContext;
use Splicewire\Beam\Ux\Support\PageEntryRef;
use Symfony\Component\HttpFoundation\Response;

/** The opt-in home page, including the same Preview, head and intake props as the starter's front door. */
class HomeEntryController
{
    public function __invoke(Request $request): Response
    {
        $page = $request->route('beamUxHomePage');
        $slug = $request->route('beamUxHomeSlug');
        $realm = $request->route('beamUxHomeRealm');
        if (! is_string($page) || ! is_string($slug) || ! is_string($realm)) {
            throw new LogicException('Home route defaults must be strings; mount it with Route::beamUxHome().');
        }
        abort_if(PageEntryRef::hiddenByPreview($slug), 404);
        $entry = PageEntryRef::for($slug);
        $model = $entry === null ? null : BeamUxEntry::query()->whereKey($entry['id'])->first();
        $preview = app(PreviewBar::class)->for($request, $model);
        $head = PageHead::forPage($slug, $request);
        $context = $request->attributes->get('beam.ux.preview');
        if ($model !== null && $context instanceof PreviewContext) {
            // A preview is an immutable view of the Release's entries and ancestry. Never fall
            // back to live ancestry here: it can differ from the version the guest is previewing.
            // The chain is root-first and ends at the page, or null when the gate refuses it. Taken as a list, the page
            // is its last node; an empty or refused chain composes no head, as a refused one always has.
            $chain = array_values(app(PublicEntryGate::class)->chainForEntry($model, $realm, null, $context) ?? []);
            $head = $chain === []
                ? []
                : app(PageHeadComposer::class)
                    ->compose(
                        $chain[array_key_last($chain)],
                        $request->path(),
                        false,
                        $request,
                        $chain,
                    )
                    ->elements();
        }

        $props = EntryPageData::from(['entry' => $entry])->toArray();
        $props = array_merge($props, $model === null ? [] : app(IntakeForms::class)->pagePropsFor($model),
            ['head' => $head], $preview === null ? [] : ['preview' => $preview]);

        return app(EntryRenderer::class)->render($page, $props);
    }
}
