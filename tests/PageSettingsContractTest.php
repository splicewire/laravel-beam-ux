<?php

namespace Splicewire\Beam\Ux\Tests;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\EntryDraftInputData;

class PageSettingsContractTest extends TestCase
{
    public function test_request_and_response_page_settings_have_distinct_schema_components(): void
    {
        $generator = app(Generator::class);
        $response = $generator->forResponse()->generate(new ReflectionClass(BeamUxEntryBodyData::class));
        $request = $generator->forRequest()->generate(new ReflectionClass(EntryDraftInputData::class));

        $this->assertArrayHasKey('EntryPageSettingsData', $response['$defs']);
        $this->assertArrayHasKey('EntryPageSettingsInputData', $request['$defs']);
        $this->assertSame([
            'title', 'parentId', 'segment', 'navOrder', 'navGroup', 'layout', 'template',
            'description', 'socialImage', 'pageType', 'publishedDate', 'childFields', 'fields', 'unclaimed',
        ], $response['$defs']['EntryPageSettingsData']['required']);
        $this->assertSame(['title'], $request['$defs']['EntryPageSettingsInputData']['required']);
    }

    public function test_committed_typescript_matches_the_declared_data(): void
    {
        $autoload = collect(get_included_files())->first(fn ($file) => str_ends_with($file, '/vendor/autoload.php'));
        $previous = getenv('BEAM_UX_AUTOLOAD');
        putenv('BEAM_UX_AUTOLOAD='.$autoload);
        ob_start();
        try {
            require dirname(__DIR__).'/scripts/export-page-settings.php';
            $generated = ob_get_contents();
        } finally {
            ob_end_clean();
            $previous === false ? putenv('BEAM_UX_AUTOLOAD') : putenv('BEAM_UX_AUTOLOAD='.$previous);
        }
        $this->assertSame(file_get_contents(__DIR__.'/Fixtures/page-settings.ts'), $generated);
    }

    public function test_page_settings_migration_is_repeatable(): void
    {
        \Illuminate\Support\Facades\Schema::create('beam_ux_entries', fn ($table) => $table->id());
        $migration = require dirname(__DIR__).'/database/migrations/shared/add_page_settings_to_beam_ux_entries_table.php.stub';
        $migration->up();
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('beam_ux_entries', ['seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date', 'first_published_at', 'last_published_at']));
    }
}
