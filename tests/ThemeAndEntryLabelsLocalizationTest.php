<?php

namespace Splicewire\Beam\Ux\Tests;

use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Ux\Data\BeamUxEntryData;
use Splicewire\Beam\Ux\Data\BeamUxEntryInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodyEnvelope;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * localization 23c: the theme and entry forms label their fields for their reader.
 *
 * - **Theme token titles.** Every token property projects a `title` (`beam-ux::theme.<schema>.titles.<token>`; docs:
 *   `docs.token_title` with the name as a parameter). In English it is the label the form drew before from the key,
 *
 *   @schemastud/seam's humanizeKey, byte for byte. Under the pseudo-locale it is marked. The registry stays English.
 * - **The entry type.** The create form's `type` options (`In(CREATABLE_TYPES)`) carry `enumNames`, and so does the entry
 *   list's `type` (UxType). In English they are the values themselves, exactly what was shown before; under the
 *   pseudo-locale they are marked.
 */
class ThemeAndEntryLabelsLocalizationTest extends TestCase
{
    /** The label seam's humanizeKey gives each key, as verified against seam's own function (display-label.ts). */
    private const SEAM_LABELS = [
        'accent' => 'Accent', 'accentHover' => 'Accent hover', 'editAccent' => 'Edit accent', 'canvas' => 'Canvas',
        'ink' => 'Ink', 'panelBg' => 'Panel bg', 'fontBody' => 'Font body', 'surfaceRaised' => 'Surface raised',
        'fgMuted' => 'Fg muted', 'background' => 'Background', 'darkAccentForeground' => 'Dark accent foreground',
        'paperRaised' => 'Paper raised', 'ink18' => 'Ink18', 'parent_tenant_id' => 'Parent tenant', 'max_retries' => 'Max retries',
    ];

    private function pseudo(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
    }

    private function editorSchema(): array
    {
        return app(EntryBodyEnvelope::class)->schemaFor(new BeamUxEntry(['type' => 'theme']));
    }

    /**
     * The class's JSON Schema from a fresh generator, which runs the configured property strategies
     * (`data-schemas.strategies`, beam's and this package's included) in the current locale.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function dataSchema(string $class): array
    {
        return (new JsonSchemaGenerator)->generate(new \ReflectionClass($class));
    }

    public function test_humanize_key_is_seams_rule(): void
    {
        $this->assertTrue(method_exists(ThemeSchemas::class, 'humanizeKey'), 'ThemeSchemas::humanizeKey (the seam rule) exists');
        foreach (self::SEAM_LABELS as $key => $label) {
            $this->assertSame($label, ThemeSchemas::humanizeKey($key), $key);
        }
    }

    public function test_every_token_title_is_the_label_the_form_drew_before_in_english(): void
    {
        foreach ([ThemeSchemas::canvas(), ThemeSchemas::shell(), ThemeSchemas::site(), ThemeSchemas::docs()] as $schema) {
            $this->assertNotEmpty($schema['properties']);
            foreach ($schema['properties'] as $key => $property) {
                $this->assertSame(ThemeSchemas::humanizeKey($key), $property['title'] ?? null, $schema['$id'].'.'.$key);
            }
        }
        $editor = $this->editorSchema()['properties'];
        $this->assertSame('Accent hover', $editor['canvas']['properties']['accentHover']['title']);
        $this->assertSame('Dark accent foreground', $editor['site']['properties']['darkAccentForeground']['title']);
    }

    public function test_the_token_titles_are_marked_under_the_pseudo_locale_and_the_registry_stays_english(): void
    {
        $this->pseudo();

        $editor = $this->editorSchema()['properties'];
        $titles = [];
        foreach (['canvas', 'site'] as $namespace) {
            foreach ($editor[$namespace]['properties'] as $key => $property) {
                $titles[$namespace.'.'.$key] = $property['title'] ?? '';
            }
        }
        $this->assertCount(11 + 17, $titles);
        foreach ($titles as $where => $title) {
            $this->assertTrue(PseudoLocale::isMarked($title), $where.': '.$title);
        }
        foreach ([ThemeSchemas::shell(Locales::PSEUDO), ThemeSchemas::docs(Locales::PSEUDO)] as $schema) {
            foreach ($schema['properties'] as $key => $property) {
                $this->assertTrue(PseudoLocale::isMarked($property['title'] ?? ''), $schema['$id'].'.'.$key.': '.($property['title'] ?? '(none)'));
            }
        }

        foreach (ThemeSchemas::all() as $schema) {
            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (isset($property['title'])) {
                    $this->assertFalse(PseudoLocale::isMarked($property['title']), $schema['$id'].'.'.$key);
                    $this->assertSame(ThemeSchemas::humanizeKey($key), $property['title']);
                }
            }
        }
    }

    public function test_the_create_forms_type_options_carry_their_labels_in_english_unchanged(): void
    {
        $type = $this->dataSchema(BeamUxEntryInputData::class)['properties']['type'];

        $this->assertSame(BeamUxEntryInputData::CREATABLE_TYPES, $type['enum']);
        $this->assertSame(BeamUxEntryInputData::CREATABLE_TYPES, $type['enumNames'] ?? null);
    }

    public function test_the_create_forms_type_options_are_marked_under_the_pseudo_locale(): void
    {
        $this->pseudo();

        $type = $this->dataSchema(BeamUxEntryInputData::class)['properties']['type'];

        $this->assertSame(BeamUxEntryInputData::CREATABLE_TYPES, $type['enum']);
        $this->assertCount(count($type['enum']), $type['enumNames'] ?? []);
        foreach ($type['enumNames'] ?? [] as $i => $name) {
            $this->assertTrue(PseudoLocale::isMarked($name), $type['enum'][$i].': '.$name);
            $this->assertSame(1, substr_count($name, PseudoLocale::OPEN), 'marked once, not twice: '.$name);
        }
    }

    public function test_the_type_labels_sit_at_beams_derived_enum_key_for_this_package(): void
    {
        // In a host, beam names this package `beam-ux` and reads a case label at
        // `<ns>::enums.<Class with \ written />.cases.<Case>.label`, so every English line is where that lookup goes.
        $this->assertSame('beam-ux', TextNamespaces::shorten('splicewire/laravel-beam-ux'));
        foreach (UxType::cases() as $case) {
            $this->assertSame($case->value, __('beam-ux::enums.Splicewire/Beam/Ux/Type/UxType.cases.'.$case->name.'.label'), $case->name);
            $this->assertSame($case->value, $case->label(), 'the declared label is the English value');
        }
    }

    public function test_the_entry_lists_type_carries_its_labels(): void
    {
        $values = array_column(UxType::cases(), 'value');
        $english = $this->dataSchema(BeamUxEntryData::class)['$defs']['UxType'];
        $this->assertSame($values, $english['enum']);
        $this->assertSame($values, $english['enumNames'] ?? null, 'English labels are the values, as the list showed them');

        $this->pseudo();
        $marked = $this->dataSchema(BeamUxEntryData::class)['$defs']['UxType'];
        $this->assertSame($values, $marked['enum'], 'the wire values are unchanged');
        $this->assertCount(count($values), $marked['enumNames'] ?? []);
        foreach ($marked['enumNames'] ?? [] as $i => $name) {
            $this->assertTrue(PseudoLocale::isMarked($name), $values[$i].': '.$name);
            $this->assertSame(1, substr_count($name, PseudoLocale::OPEN), 'marked once, not twice: '.$name);
        }
    }
}
