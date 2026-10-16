<?php

namespace Splicewire\Beam\Ux\Support;

use Illuminate\Http\Request;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Throwable;

final class EditorProps
{
    public static function canAuthor(Request $request): bool
    {
        return $request->user()?->can('ux.author') ?? false;
    }

    /** @return array<string, mixed> */
    public static function theme(): array
    {
        try {
            return app(ThemeResolver::class)->resolve();
        } catch (Throwable) {
            return [];
        }
    }
}
