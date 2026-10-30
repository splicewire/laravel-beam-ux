<?php

namespace Splicewire\Beam\Ux\Schema;

use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Storage\ScratchStorageRoot;
use Splicewire\Beam\Ux\Theme\ThemeResolver;

/**
 * Theme token JSON Schema source (theme-entries-and-authoring ticket 01). Three built-in namespaced
 * sub-schemas — `canvas`/`shell`/`site`, per the wayfinder resolution: distinct shapes today,
 * namespaced so a host overrides one without touching the others — plus a root `theme` schema
 * that `$ref`-composes them and optional tagged namespaces by bare `$id`. This is the ONLY source of truth; the committed
 * package artifacts under {@see self::directory()} are a generated, gitignored projection
 * ({@see BeamUxServiceProvider::registerThemeSchemas()} regenerates them on
 * every boot) — never hand-edit a `.schema.json` file directly.
 *
 * `canvas` mirrors `splicewire/beam`'s `CanvasTheme` TS interface (`packages/beam/beam-ux/src/canvas/css.ts`)
 * exactly — 9 colors + 2 font families — with `DEFAULT_CANVAS_THEME`'s values as JSON Schema
 * defaults (the package-shipped neutral theme, not any one host's override).
 * Those defaults are the app's own tokens (beam-ux `tokens.css`, light: `--beam-green`, the `--beam-rail` panels), so the
 * editor chrome reads as the app it sits in (launch ticket 05 item 4).
 *
 * `shell` mirrors the fleet-shared `--shell-*` custom-property set
 * (`@schemastud/mainframe/os/shell.css`) — names/order fixed per the umbrella GOAL's guardrail,
 * camelCased without the `--shell-` prefix for JSON Schema property keys — with that file's own
 * neutral grayscale defaults.
 *
 * `site` is net-new (today's site palette is unstructured raw hex literals inline in host JSX,
 * no existing shape to match) — a reasonable first namespace: a small palette plus the `:hover`
 * accent treatment every host site currently hand-rolls.
 *
 * `site` carries its DARK variant as flat `dark*` siblings of the light slots (`darkBackground` beside
 * `background`), not a nested `dark` object. Flat keeps the `css` codec's round-trip exact
 * (`site.darkBackground` ⇄ `--theme-site-dark-background`; a nested `site.dark.background` would decode
 * to the same name and re-encode as `site.darkBackground`), keeps {@see ThemeResolver::defaults()} a
 * one-level read, and lets a tier override one dark slot alone through the resolver's per-token merge.
 * The site layout applies the `dark*` values whenever the visitor's scheme is dark (the app's stored
 * appearance, else the system preference — the same `.dark` class the app shell toggles).
 */
class ThemeSchemas
{
    public const CANVAS_ID = 'theme.canvas';

    public const SHELL_ID = 'theme.shell';

    public const SITE_ID = 'theme.site';

    public const ROOT_ID = 'theme';

    public const DOCS_ID = 'theme.docs';

    public const SOURCES = 'beam-ux.theme-namespace-sources';

    /**
     * Where the generated, gitignored `FilesystemSchemaRegistry` artifacts for {@see self::all()} live.
     *
     * PER-HOST, under the host's own `storage/app/beam-ux/` (beside the compiled artifacts and honouring
     * `beam.ux.scratch_storage_root`) — NEVER the package's shared `resources/schemas/theme`.
     * landing-motion ticket 25: that package directory is one checkout shared by every host linking the
     * live `splicewire/laravel-beam-ux`, so a host booting a different `theme` shape ran
     * {@see WiresThemeSchemas::forgetStaleThemeArtifact()} (unlink) and `FilesystemSchemaRegistry::register()`
     * (a non-atomic `file_put_contents`) over the artifact another host was mid-read of — two shapes
     * replacing each other's single `theme.<id-hash>.schema.json` and 500ing a request that landed
     * between them (`file_get_contents(...): No such file` / `Refusing to overwrite frozen schema "theme"`).
     * A per-host location ends the sharing; `register()`'s frozen-schema fingerprint guard is unchanged.
     */
    public static function directory(): string
    {
        $root = ScratchStorageRoot::resolve(! app()->isProduction());
        $base = $root ?? storage_path('app');

        return $base.'/beam-ux/theme-schemas';
    }

    /** @return array<int, array<string, mixed>> Built-in and contributed schemas, followed by their composed root. */
    public static function all(): array
    {
        $namespaces = self::namespaces();

        return [...$namespaces, self::root($namespaces)];
    }

    /** @return array<string, mixed> */
    public static function canvas(): array
    {
        return [
            '$id' => self::CANVAS_ID,
            'type' => 'object',
            'title' => 'Canvas theme',
            'description' => 'The beam-ux visual editor canvas palette (CanvasTheme: 9 colors + 2 font families).',
            'additionalProperties' => false,
            'properties' => [
                'accent' => self::color('Primary accent (selection outline, active toggles, save button).', '#14803f'),
                'accentHover' => self::color('Accent hover (save button hover).', '#0f5f2e'),
                'editAccent' => self::color('Editable-text (contenteditable) outline.', '#35d07a'),
                'canvas' => self::color('The canvas surface (the live page background).', '#ffffff'),
                'ink' => self::color('Ink / body text on the canvas.', '#2e2c26'),
                'panelBg' => self::color('Panel (bar / palette / inspector) background.', '#11201a'),
                'rootBg' => self::color('Editor root backdrop (behind the panels, in window mode).', '#0d1a14'),
                'panelFg' => self::color('Primary panel text.', '#eaf6ee'),
                'muted' => self::color('Muted panel text (hints, labels).', '#83998c'),
                'fontBody' => self::font('Font family for body/panel chrome.', 'system-ui, sans-serif'),
                'fontMono' => self::font('Font family for code/mono chrome.', 'ui-monospace, monospace'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function shell(): array
    {
        return [
            '$id' => self::SHELL_ID,
            'type' => 'object',
            'title' => 'Shell theme',
            'description' => 'The mainframe OS shell chrome (--shell-* custom properties, @schemastud/mainframe/os/shell.css).',
            'additionalProperties' => false,
            'properties' => [
                'surface' => self::color('--shell-surface: window/taskbar background.', '#f4f4f5'),
                'surfaceRaised' => self::color('--shell-surface-raised: bars, menus, window chrome.', '#ffffff'),
                'fg' => self::color('--shell-fg: primary shell text.', '#1c1c1e'),
                'fgMuted' => self::color('--shell-fg-muted: statusbar/hint text.', '#6b7280'),
                'accent' => self::color('--shell-accent: focus/active accent.', '#52525b'),
                'edge' => self::color('--shell-edge: borders/dividers.', '#d4d4d8'),
                'radius' => self::str('--shell-radius: chrome corner radius (CSS length).', '8px'),
                'shadow' => self::str('--shell-shadow: window/menu drop shadow (CSS box-shadow).', '0 8px 24px rgba(0, 0, 0, 0.16)'),
                'font' => self::font('--shell-font: chrome body font stack.', 'system-ui, sans-serif'),
                'fontMono' => self::font('--shell-font-mono: statusbar/mono chrome font stack.', 'ui-monospace, SFMono-Regular, Menlo, monospace'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function site(): array
    {
        return [
            '$id' => self::SITE_ID,
            'type' => 'object',
            'title' => 'Site theme',
            'description' => 'The public site palette — net-new namespace, a first reasonable shape (no prior structure existed).',
            'additionalProperties' => false,
            'properties' => [
                'background' => self::color('Page background.', '#FFFFFF'),
                'foreground' => self::color('Primary body text.', '#1A1A1A'),
                'muted' => self::color('Secondary/muted text.', '#6B7280'),
                'accent' => self::color('Primary accent (links, buttons).', '#4F7CFF'),
                'accentHover' => self::color('Accent :hover treatment (buttons, links).', '#3A63E0'),
                'accentForeground' => self::color('Text on an accent fill (the primary button label).', '#FFFFFF'),
                'border' => self::color('Dividers/card borders.', '#D4D4D8'),
                // Typography (theme-entries-and-authoring follow-up: a brand-distinct sub-site needs more
                // than recoloring — mirrors canvas's fontBody/fontMono naming, split a third way since a
                // site brand commonly carries a serif/display face too, unlike the neutral editor chrome).
                'fontSans' => self::font('Primary UI/body font stack.', 'system-ui, sans-serif'),
                'fontSerif' => self::font('Display/headline font stack (falls back to fontSans when a brand has none).', 'system-ui, sans-serif'),
                'fontMono' => self::font('Code/label font stack.', 'ui-monospace, monospace'),
                // The dark variant — applied when the visitor's scheme is dark. Defaults are a neutral
                // dark pairing of the light defaults above: a near-black page, light ink, and an accent
                // lifted far enough to read on it, with a DARK label on that lighter accent.
                'darkBackground' => self::color('Dark scheme: page background.', '#0B0F17'),
                'darkForeground' => self::color('Dark scheme: primary body text.', '#E5E7EB'),
                'darkMuted' => self::color('Dark scheme: secondary/muted text.', '#9CA3AF'),
                'darkAccent' => self::color('Dark scheme: primary accent (links, buttons).', '#8AA4FF'),
                'darkAccentHover' => self::color('Dark scheme: accent :hover treatment.', '#A9BDFF'),
                'darkAccentForeground' => self::color('Dark scheme: text on an accent fill.', '#0B0F17'),
                'darkBorder' => self::color('Dark scheme: dividers/card borders.', '#262B36'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> Built-ins plus optional, typed capability contributions. */
    public static function namespaces(): array
    {
        $schemas = [self::canvas(), self::shell(), self::site()];
        $ids = array_column($schemas, '$id');
        foreach (function_exists('app') ? app()->tagged(self::SOURCES) : [] as $source) {
            if (! $source instanceof ThemeNamespaceSource) {
                throw new \InvalidArgumentException('Theme namespace sources must implement '.ThemeNamespaceSource::class);
            }
            $schema = $source->schema();
            $id = $schema['$id'] ?? null;
            if (! is_string($id) || ! preg_match('/^theme\.[a-z][a-z0-9_-]*$/D', $id) || in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Theme namespace sources must declare a distinct theme.<namespace> id.');
            }
            $schemas[] = $schema;
            $ids[] = $id;
        }

        return $schemas;
    }

    /**
     * Optional docs palette. The docs capability registers it; generic UX does not enable it itself.
     * Defaults mirror beam-ux tokens.css, with flat dark siblings for the CSS codec round trip.
     *
     * @return array<string, mixed>
     */
    public static function docs(): array
    {
        $defaults = [
            'paper' => '#eef1ee',
            'paperRaised' => '#ffffff',
            'muted' => '#e6eae5',
            'ink' => '#2e2c26',
            'inkMuted' => 'rgba(17, 32, 26, 0.5)',
            'ink18' => 'rgba(17, 32, 26, 0.18)',
            'line' => '#e3e6e2',
            'green' => '#14803f',
            'greenDeep' => '#0f5f2e',
            'greenTint' => '#eaf6ee',
            'signal' => '#35d07a',
            'signal13' => 'rgba(53, 208, 122, 0.13)',
            'signal18' => 'rgba(53, 208, 122, 0.18)',
            'warn' => '#e8a13a',
            'warnDeep' => '#9a6608',
            'warnTint' => '#fdf3e3',
            'amber' => '#d97706',
            'info' => '#2b63a7',
            'infoTint' => '#e8eff8',
            'danger' => '#a3271b',
            'dangerTint' => '#f8e9e7',
            'rail' => '#11201a',
            'railDeep' => '#0d1a14',
            'railFg' => 'rgba(207, 233, 216, 0.6)',
            'railActive' => '#eaf6ee',
            'railAvatar' => '#1d3527',
            'ink03' => 'rgba(17, 32, 26, 0.03)',
            'ink04' => 'rgba(17, 32, 26, 0.04)',
            'ink05' => 'rgba(17, 32, 26, 0.05)',
            'ink08' => 'rgba(17, 32, 26, 0.08)',
            'ink10' => 'rgba(17, 32, 26, 0.1)',
            'ink12' => 'rgba(17, 32, 26, 0.12)',
            'ink15' => 'rgba(17, 32, 26, 0.15)',
            'ink20' => 'rgba(17, 32, 26, 0.2)',
            'ink22' => 'rgba(17, 32, 26, 0.22)',
            'ink30' => 'rgba(17, 32, 26, 0.3)',
            'ink35' => 'rgba(17, 32, 26, 0.35)',
            'ink40' => 'rgba(17, 32, 26, 0.4)',
            'ink45' => 'rgba(17, 32, 26, 0.45)',
            'ink50' => 'rgba(17, 32, 26, 0.5)',
            'ink55' => 'rgba(17, 32, 26, 0.55)',
            'ink60' => 'rgba(17, 32, 26, 0.6)',
            'ink70' => 'rgba(17, 32, 26, 0.7)',
            'ink80' => 'rgba(17, 32, 26, 0.8)',
            'darkPaper' => 'oklch(0.2 0.02 165)',
            'darkPaperRaised' => 'oklch(0.24 0.022 165)',
            'darkMuted' => 'oklch(0.28 0.02 165)',
            'darkInk' => 'oklch(0.93 0.015 160)',
            'darkInkMuted' => 'oklch(0.93 0.015 160 / 0.6)',
            'darkInk18' => 'oklch(0.93 0.015 160 / 0.18)',
            'darkLine' => 'oklch(0.32 0.02 165)',
            'darkGreen' => 'oklch(0.78 0.17 155)',
            'darkGreenDeep' => 'oklch(0.82 0.15 155)',
            'darkGreenTint' => 'oklch(0.78 0.17 155 / 0.16)',
            'darkSignal' => 'oklch(0.78 0.17 155)',
            'darkSignal13' => 'oklch(0.78 0.17 155 / 0.16)',
            'darkSignal18' => 'oklch(0.78 0.17 155 / 0.22)',
            'darkWarn' => 'oklch(0.8 0.14 75)',
            'darkWarnDeep' => 'oklch(0.82 0.13 78)',
            'darkWarnTint' => 'oklch(0.8 0.14 75 / 0.16)',
            'darkAmber' => 'oklch(0.8 0.14 75)',
            'darkInfo' => 'oklch(0.72 0.13 245)',
            'darkInfoTint' => 'oklch(0.72 0.13 245 / 0.16)',
            'darkDanger' => 'oklch(0.72 0.18 25)',
            'darkDangerTint' => 'oklch(0.72 0.18 25 / 0.16)',
            'darkRail' => 'oklch(0.17 0.02 165)',
            'darkRailDeep' => 'oklch(0.14 0.02 165)',
            'darkRailFg' => 'oklch(0.72 0.03 160)',
            'darkRailActive' => 'oklch(0.95 0.015 160)',
            'darkRailAvatar' => 'oklch(0.28 0.03 160)',
            'darkInk03' => 'oklch(0.93 0.015 160 / 0.03)',
            'darkInk04' => 'oklch(0.93 0.015 160 / 0.04)',
            'darkInk05' => 'oklch(0.93 0.015 160 / 0.05)',
            'darkInk08' => 'oklch(0.93 0.015 160 / 0.08)',
            'darkInk10' => 'oklch(0.93 0.015 160 / 0.1)',
            'darkInk12' => 'oklch(0.93 0.015 160 / 0.12)',
            'darkInk15' => 'oklch(0.93 0.015 160 / 0.15)',
            'darkInk20' => 'oklch(0.93 0.015 160 / 0.2)',
            'darkInk22' => 'oklch(0.93 0.015 160 / 0.22)',
            'darkInk30' => 'oklch(0.93 0.015 160 / 0.3)',
            'darkInk35' => 'oklch(0.93 0.015 160 / 0.35)',
            'darkInk40' => 'oklch(0.93 0.015 160 / 0.4)',
            'darkInk45' => 'oklch(0.93 0.015 160 / 0.45)',
            'darkInk50' => 'oklch(0.93 0.015 160 / 0.5)',
            'darkInk55' => 'oklch(0.93 0.015 160 / 0.55)',
            'darkInk60' => 'oklch(0.93 0.015 160 / 0.6)',
            'darkInk70' => 'oklch(0.93 0.015 160 / 0.7)',
            'darkInk80' => 'oklch(0.93 0.015 160 / 0.8)',
        ];
        $properties = [];
        foreach ($defaults as $key => $default) {
            $properties[$key] = self::color('Docs '.$key.' token.', $default);
        }
        // tokens.css intentionally leaves brand fonts to the host; inherit preserves that default.
        $properties['fontSans'] = self::font('Docs body font family.', 'inherit');
        $properties['fontMono'] = self::font('Docs code font family.', 'ui-monospace, monospace');

        return ['$id' => self::DOCS_ID, 'type' => 'object', 'title' => 'Docs theme', 'additionalProperties' => false, 'properties' => $properties];
    }

    /** @return array<string, mixed> */
    public static function root(?array $namespaces = null): array
    {
        $properties = [];
        foreach ($namespaces ?? self::namespaces() as $schema) {
            $properties[substr($schema['$id'], strlen('theme.'))] = ['$ref' => $schema['$id']];
        }

        return [
            '$id' => self::ROOT_ID,
            'type' => 'object',
            'title' => 'Theme',
            'description' => 'The composed theme entry body — one namespace per surface-agnostic token group.',
            'properties' => $properties,
        ];
    }

    /** @return array{type: string, format: string, description: string, default: string} */
    private static function color(string $description, string $default): array
    {
        return ['type' => 'string', 'format' => 'color', 'description' => $description, 'default' => $default];
    }

    /** @return array{type: string, description: string, default: string} */
    private static function font(string $description, string $default): array
    {
        return self::str($description, $default);
    }

    /** @return array{type: string, description: string, default: string} */
    private static function str(string $description, string $default): array
    {
        return ['type' => 'string', 'description' => $description, 'default' => $default];
    }
}
