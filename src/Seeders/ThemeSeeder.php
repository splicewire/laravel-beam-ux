<?php

namespace Splicewire\Beam\Ux\Seeders;

use Illuminate\Database\Seeder;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\AsSystemWriter;

/**
 * Seeds this host's central theme entry (theme-entries-and-authoring ticket `str-01`) with TODAY's
 * shipped values — the same neutral slate palette `resources/js/editor/theme.ts`'s `NEUTRAL_THEME`
 * (canvas), `resources/js/os/shell-config.tsx`'s `OS_SHELL_CSS` (shell), and
 * `resources/js/layouts/site-layout.tsx`'s `CSS` (site) already hardcode — so migrate+seed on a fresh
 * install renders byte-for-byte the same output `ThemeResolver::resolve()` cascades onto once this
 * row exists, and an author can then override individual tokens from the entry going forward.
 *
 * There is no disk-seeding mechanism for a THEME entry (unlike `NavSeeder`'s
 * `resources/beam-ux/nav.{yml,json}` — `RegisterEntriesFromDisk`/`NavSource` are file-source/nav-row
 * shaped respectively, neither fits a single structured `{canvas,shell,site}` body). This packaged seeder creates the theme through the same publication service as author saves.
 *
 * Idempotent: re-running refreshes the SAME particle's payload (never mints a second orphaned one).
 */
class ThemeSeeder extends Seeder
{
    use AsSystemWriter;

    public function run(): void
    {
        $this->asSystemWriter(fn () => $this->seedShipped());
    }

    private function seedShipped(): void
    {
        app(Reasserter::class)->seedBody(
            ['namespace' => ThemeResolver::NAMESPACE, 'slug' => ThemeResolver::SLUG],
            ['type' => UxType::Theme, 'title' => 'Theme', 'format' => 'css'],
            $this->body(),
            'package:splicewire/laravel-beam-ux/theme',
        );
    }

    /** @return array{canvas: array<string, string>, shell: array<string, string>, site: array<string, string>} */
    private function body(): array
    {
        return [
            // resources/js/editor/theme.ts's NEUTRAL_THEME, verbatim.
            'canvas' => [
                'accent' => '#0f172a',
                'accentHover' => '#1e293b',
                'editAccent' => '#2563eb',
                'canvas' => '#ffffff',
                'ink' => '#0f172a',
                'panelBg' => '#0f172a',
                'rootBg' => '#020617',
                'panelFg' => '#e2e8f0',
                'muted' => '#64748b',
                'fontBody' => 'system-ui, sans-serif',
                'fontMono' => 'ui-monospace, monospace',
            ],
            // resources/js/os/shell-config.tsx's OS_SHELL_CSS 10-var block, verbatim.
            'shell' => [
                'surface' => '#0f172a',
                'surfaceRaised' => 'rgba(15,23,42,.88)',
                'fg' => '#f1f5f9',
                'fgMuted' => '#94a3b8',
                'accent' => '#3b82f6',
                'edge' => '#334155',
                'radius' => '14px',
                'shadow' => '0 40px 90px -40px rgba(0,0,0,.7)',
                'font' => 'system-ui,sans-serif',
                'fontMono' => 'ui-monospace,monospace',
            ],
            // Derived from resources/js/layouts/site-layout.tsx's CSS + inline styles: muted =
            // .navlink's base color, accent/accentHover = .btn-primary's background/:hover, background
            // = the layout's page background, border = the header/footer's existing rgba divider.
            'site' => [
                'background' => '#f8fafc',
                'foreground' => '#0f172a',
                'muted' => '#475569',
                'accent' => '#0f172a',
                'accentHover' => '#1e293b',
                'border' => 'rgba(15,23,42,.08)',
            ],
        ];
    }
}
