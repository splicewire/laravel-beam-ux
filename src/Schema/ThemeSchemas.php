<?php

namespace Splicewire\Beam\Ux\Schema;

use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Theme\ThemeResolver;

/**
 * Theme token JSON Schema source (theme-entries-and-authoring ticket 01). Three namespaced
 * sub-schemas — `canvas`/`shell`/`site`, per the wayfinder resolution: distinct shapes today,
 * namespaced so a host overrides one without touching the others — plus a root `theme` schema
 * that `$ref`-composes all three by bare `$id`. This is the ONLY source of truth; the committed
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

    /** Where the generated, gitignored `FilesystemSchemaRegistry` artifacts for {@see self::all()} live. */
    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/resources/schemas/theme';
    }

    /** @return array<int, array<string, mixed>> all four schemas, in registration order. */
    public static function all(): array
    {
        return [self::canvas(), self::shell(), self::site(), self::root()];
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

    /** @return array<string, mixed> */
    public static function root(): array
    {
        return [
            '$id' => self::ROOT_ID,
            'type' => 'object',
            'title' => 'Theme',
            'description' => 'The composed theme entry body — one namespace per surface-agnostic token group.',
            'properties' => [
                'canvas' => ['$ref' => self::CANVAS_ID],
                'shell' => ['$ref' => self::SHELL_ID],
                'site' => ['$ref' => self::SITE_ID],
            ],
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
