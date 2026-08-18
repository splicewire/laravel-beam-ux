<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Format\UxFormat;

/**
 * `CssBodyCodec` is a `UxType::Theme` entry's default `BodyCodec` — `PlacedDiskMirror` calls it on
 * every theme save so a theme entry gets the SAME git-trackable disk record a page/component already
 * gets from `TsxBodyCodec`. Found live (splicewire host app): before `BeamUxEntry::defaultFormatFor()`
 * existed, every theme entry silently resolved `TsxBodyCodec` instead — which returns an EMPTY string
 * for a `{canvas, site}` body — so every theme save was writing a blank `.tsx` file with no error.
 */
class CssBodyCodecTest extends TestCase
{
    public function test_format_and_extension(): void
    {
        $codec = new CssBodyCodec;

        $this->assertSame(UxFormat::Css, $codec->format());
        $this->assertSame('css', $codec->extension());
    }

    public function test_decode_prints_a_root_block_from_a_nested_body(): void
    {
        $css = (new CssBodyCodec)->decode([
            'site' => ['background' => '#eef1ee', 'accentHover' => '#0f5f2e'],
            'canvas' => ['editAccent' => '#35d07a'],
        ]);

        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString('--theme-site-background: #eef1ee;', $css);
        $this->assertStringContainsString('--theme-site-accent-hover: #0f5f2e;', $css);
        $this->assertStringContainsString('--theme-canvas-edit-accent: #35d07a;', $css);
    }

    public function test_decode_skips_empty_and_non_string_values(): void
    {
        $css = (new CssBodyCodec)->decode([
            'site' => ['background' => '#eef1ee', 'muted' => ''],
        ]);

        $this->assertStringContainsString('--theme-site-background', $css);
        $this->assertStringNotContainsString('--theme-site-muted', $css);
    }

    public function test_encode_is_the_inverse_of_decode(): void
    {
        $codec = new CssBodyCodec;

        $original = [
            'site' => ['background' => '#eef1ee', 'accentHover' => '#0f5f2e'],
            'canvas' => ['editAccent' => '#35d07a'],
        ];

        $roundTripped = $codec->encode($codec->decode($original));

        $this->assertSame($original, $roundTripped);
    }

    public function test_encode_reads_a_light_hand_edit_of_a_generated_file(): void
    {
        // Round-trip fidelity matters here specifically because a generated theme.css is meant to be
        // human-inspectable (git diffs) — a value tweaked by hand should still parse.
        $css = <<<CSS
            /* some header comment, possibly hand-added, with an apostrophe like "don't touch" */
            :root {
                --theme-site-background: #000000;
            }
            CSS;

        $body = (new CssBodyCodec)->encode($css);

        $this->assertSame('#000000', $body['site']['background']);
    }

    public function test_encode_degrades_to_an_empty_body_when_theres_no_root_block(): void
    {
        $this->assertSame([], (new CssBodyCodec)->encode('not css at all'));
    }
}
