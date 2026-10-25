<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Ux\Data\EntryPageSettingsData;
use Splicewire\Beam\Ux\Files\FileConflict;
use Splicewire\Beam\Ux\Files\MdxPageFile;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** ADR-0233 d3: the canonical page-settings header of a blocks MDX page's file. */
class MdxPageFileTest extends TestCase
{
    public function test_the_header_prints_page_settings_in_fixed_order_without_defaults_or_the_parent(): void
    {
        $settings = new EntryPageSettingsData(
            title: 'Guide', parentId: '01a001bc-0000-7000-8000-0000000000aa', segment: 'guide', navOrder: 0,
            layout: 'docs', description: 'How it works', pageType: 'WebPage',
        );

        $this->assertSame(
            "---\ntitle: Guide\nsegment: guide\nnavOrder: 0\nlayout: docs\ndescription: How it works\n---\n\nBody\n",
            MdxPageFile::print($settings, "Body\n"),
        );
    }

    public function test_unclaimed_keys_print_sorted_after_the_declared_ones(): void
    {
        $settings = new EntryPageSettingsData(title: 'Guide', unclaimed: ['schema_type' => 'HowTo', 'audience' => 'admins']);

        $this->assertSame("---\ntitle: Guide\naudience: admins\nschema_type: HowTo\n---\n\nBody\n", MdxPageFile::print($settings, "Body\n"));
        $this->assertSame(['audience' => 'admins', 'schema_type' => 'HowTo'], MdxPageFile::read(MdxPageFile::print($settings, "Body\n"))['unknown']);
    }

    public function test_an_unclaimed_key_never_prints_over_a_declared_one(): void
    {
        $settings = new EntryPageSettingsData(title: 'Help', fields: ['servings' => 2], unclaimed: ['title' => 'Injected', 'servings' => '9', 'audience' => 'admins']);

        $this->assertSame("---\ntitle: Help\nservings: 2\naudience: admins\n---\n\nBody\n", MdxPageFile::print($settings, "Body\n"));
    }

    public function test_page_fields_print_after_the_settings_and_before_the_unclaimed_keys(): void
    {
        $settings = new EntryPageSettingsData(title: 'Pie', fields: ['servings' => 4, 'vegetarian' => false], unclaimed: ['audience' => 'cooks']);

        $this->assertSame("---\ntitle: Pie\nservings: 4\nvegetarian: false\naudience: cooks\n---\n\nBody\n", MdxPageFile::print($settings, "Body\n"));
    }

    public function test_reading_accepts_either_key_spelling_and_names_the_keys_nothing_declares(): void
    {
        $file = MdxPageFile::read("---\ntitle: Guide\nnav_order: 3\nsocial-image: /og.png\naudience: admins\n---\n\nBody\n");

        $this->assertSame(['title' => 'Guide', 'navOrder' => '3', 'socialImage' => '/og.png'], $file['fields']);
        $this->assertSame(['audience' => 'admins'], $file['unknown']);
        $this->assertSame("Body\n", $file['body']);
        $this->assertSame(3, MdxPageFile::settingsFrom($file['fields'], [], new BeamUxEntry(['type' => 'page']))->navOrder);
    }

    public function test_a_file_without_a_header_is_not_a_page_file(): void
    {
        $this->expectException(FileConflict::class);

        MdxPageFile::read("Body\n");
    }

    public function test_a_value_the_flat_grammar_would_not_read_back_is_refused(): void
    {
        foreach (["Two\nlines", ' leading space', '"quoted"'] as $title) {
            try {
                MdxPageFile::assertPrintable(new EntryPageSettingsData(title: $title));
                $this->fail("Printed an unreadable title: {$title}");
            } catch (ValidationException $failure) {
                $this->assertArrayHasKey('pageSettings.title', $failure->errors());
            }
        }

        try {
            MdxPageFile::assertPrintable(new EntryPageSettingsData(title: 'Guide', unclaimed: ['audience' => ' admins']));
            $this->fail('Printed an unreadable unclaimed value');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey('pageSettings.unclaimed.audience', $failure->errors());
        }

        MdxPageFile::assertPrintable(new EntryPageSettingsData(title: 'Guide: the "real" one'));
        $this->addToAssertionCount(1);
    }
}
