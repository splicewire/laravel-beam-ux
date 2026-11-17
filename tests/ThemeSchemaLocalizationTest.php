<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodyEnvelope;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/**
 * localization 23b: the theme schema's labels and descriptions read for their reader.
 * - The theme editor's projection (EntryBodyEnvelope::schemaFor) resolves every title and description through
 *   `beam-ux::theme.*` in the request's locale: under the pseudo-locale each one is marked.
 * - The registry artifacts (ThemeSchemas::all(), written at boot) stay English whatever the request's locale, so their
 *   fingerprints never change with a reader.
 */
class ThemeSchemaLocalizationTest extends TestCase
{
    private function pseudo(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
    }

    /** @return list<string> every title and description in a schema, depth first */
    private function texts(array $schema): array
    {
        $texts = [];
        foreach (['title', 'description'] as $slot) {
            if (isset($schema[$slot]) && is_string($schema[$slot])) {
                $texts[] = $schema[$slot];
            }
        }
        foreach ($schema['properties'] ?? [] as $property) {
            if (is_array($property)) {
                $texts = [...$texts, ...$this->texts($property)];
            }
        }

        return $texts;
    }

    private function editorSchema(): array
    {
        return app(EntryBodyEnvelope::class)->schemaFor(new BeamUxEntry(['type' => 'theme']));
    }

    public function test_the_editor_projection_marks_every_label_and_description_under_the_pseudo_locale(): void
    {
        $english = $this->texts($this->editorSchema());
        $this->assertContains('Primary accent (selection outline, active toggles, save button).', $english);
        $this->assertContains('Ink / body text on the canvas.', $english);

        $this->pseudo();
        $marked = $this->texts($this->editorSchema());

        // The entry title, canvas and site: their titles, descriptions and every token's description, and (localization
        // 23c) every token's title: 11 canvas + 17 site.
        $this->assertCount(count($english), $marked);
        $this->assertCount(1 + (2 + 11 + 11) + (2 + 17 + 17), $marked);
        foreach ($marked as $text) {
            $this->assertTrue(PseudoLocale::isMarked($text), $text);
        }
    }

    public function test_every_builder_resolves_its_copy_through_the_seam_in_the_locale_asked(): void
    {
        $this->pseudo();
        foreach ([ThemeSchemas::canvas(Locales::PSEUDO), ThemeSchemas::shell(Locales::PSEUDO), ThemeSchemas::site(Locales::PSEUDO), ThemeSchemas::docs(Locales::PSEUDO)] as $schema) {
            foreach ($this->texts($schema) as $text) {
                $this->assertTrue(PseudoLocale::isMarked($text), $schema['$id'].': '.$text);
            }
        }
        // The docs token line takes the token name as a parameter: the line is marked, the name passes through as is.
        $paper = ThemeSchemas::docs(Locales::PSEUDO)['properties']['paper']['description'];
        $this->assertTrue(PseudoLocale::isMarked($paper), $paper);
        $this->assertStringContainsString(' paper ', $paper);
        $this->assertSame('Docs paper token.', ThemeSchemas::docs()['properties']['paper']['description']);
    }

    public function test_the_registry_artifacts_stay_english_under_any_request_locale(): void
    {
        $english = json_encode(ThemeSchemas::all());

        $this->pseudo();

        $this->assertSame($english, json_encode(ThemeSchemas::all()));
        foreach (ThemeSchemas::all() as $schema) {
            foreach ($this->texts($schema) as $text) {
                $this->assertFalse(PseudoLocale::isMarked($text), $schema['$id'].': '.$text);
                $this->assertStringNotContainsString('beam-ux::theme.', $text);
            }
        }
    }
}
