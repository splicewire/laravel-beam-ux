<?php

declare(strict_types=1);

namespace Splicewire\Beam\Ux\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props `Route::beamRealmConsole()` passes to `@splicewire/beam-inertia`'s `pages/frame/console` for one realm.
 *
 * It mirrors that package's `FrameRealmContext` (`src/frame/realm.tsx`) field for field, nullability included:
 *  - `realm` keys the client's manifest cache, so a second realm's console is never served the first's manifest;
 *  - `basename` is the `<BrowserRouter basename>`, so realm-relative leaves match under the realm's base;
 *  - `manifestUrl` is the realm's own manifest mount, `{base}/frame/manifest`.
 *
 * `realm` is nullable there because a host may mount one unscoped console; the macro always names its realm.
 */
#[TypeScript]
final class RealmConsolePageData extends Data
{
    public function __construct(
        public ?string $realm,
        public string $basename,
        public string $manifestUrl,
    ) {}
}
