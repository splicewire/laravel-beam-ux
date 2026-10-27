<?php

namespace Splicewire\Beam\Ux\Tests;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\EntryDraftInputData;
use Splicewire\Beam\Ux\Data\EntryPageSettingsInputData;
use Splicewire\Beam\Ux\Data\RecordPageData;
use Splicewire\Beam\Ux\Data\RecordPageInputData;

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
            'description', 'socialImage', 'pageType', 'publishedDate', 'fileLanguage', 'childFields', 'fields', 'unclaimed', 'recordPage',
        ], $response['$defs']['EntryPageSettingsData']['required']);
        $this->assertSame(['title'], $request['$defs']['EntryPageSettingsInputData']['required']);
    }

    public function test_a_record_page_has_one_shape_per_name_across_request_and_response(): void
    {
        $generator = app(Generator::class);
        $response = $generator->forResponse()->generate(new ReflectionClass(BeamUxEntryBodyData::class));
        $request = $generator->forRequest()->generate(new ReflectionClass(EntryDraftInputData::class));

        // A default makes a property optional only in a request, so one class nested on both axes renders two shapes.
        $this->assertSame(['collection', 'field', 'titleField', 'descriptionField', 'socialImageField'], $response['$defs']['RecordPageData']['required']);
        $this->assertArrayNotHasKey('RecordPageData', $request['$defs']);
        $this->assertSame(['collection', 'field'], $request['$defs']['RecordPageInputData']['required']);
        foreach (array_intersect_key($request['$defs'], $response['$defs']) as $name => $definition) {
            $this->assertEquals($response['$defs'][$name], $definition, "$name renders a different shape on each axis under one name");
        }
    }

    public function test_openapi_over_the_draft_save_and_body_endpoints_names_no_colliding_component(): void
    {
        // The L1 replay's red (2026-10-27): a host documenting both operations failed its install, because the Scribe
        // bridge refused "OpenAPI component name collision [RecordPageData]". The two endpoints carry the schemas the
        // bridge's request and response strategies stash for them.
        $generator = app(Generator::class);
        $endpoints = [
            OutputEndpointData::create([
                'httpMethods' => ['POST'], 'uri' => 'api/v1/beam-ux-entries/{id}/save-draft',
                'custom' => ['dataRequestSchema' => $generator->forRequest()->generate(new ReflectionClass(EntryDraftInputData::class))],
            ]),
            OutputEndpointData::create([
                'httpMethods' => ['GET'], 'uri' => 'api/v1/beam-ux-entries/{id}/body',
                'custom' => ['dataResponseSchemas' => [['status' => 200, 'schema' => $generator->forResponse()->generate(new ReflectionClass(BeamUxEntryBodyData::class))]]],
            ]),
        ];

        $root = (new DataSchemaGenerator(new DocumentationConfig([])))->root([], [['description' => '', 'name' => 'Entries', 'endpoints' => $endpoints]]);

        $components = $root['components']['schemas'];
        $this->assertSame(['collection', 'field', 'titleField', 'descriptionField', 'socialImageField'], $components['RecordPageData']['required']);
        $this->assertSame(['collection', 'field'], $components['RecordPageInputData']['required']);
        $this->assertStringContainsString('#/components/schemas/RecordPageInputData', json_encode($components['EntryPageSettingsInputData'], JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('#/components/schemas/RecordPageData', json_encode($components['EntryPageSettingsData'], JSON_UNESCAPED_SLASHES));
    }

    public function test_the_input_record_page_becomes_the_settings_record_page(): void
    {
        $input = EntryPageSettingsInputData::from(['title' => 'Item', 'recordPage' => ['collection' => 'products', 'field' => 'slug', 'titleField' => 'name']]);

        $this->assertInstanceOf(RecordPageInputData::class, $input->recordPage);
        $this->assertEquals(new RecordPageData('products', 'slug', 'name'), $input->toPageSettingsData()->recordPage);
        $this->assertNull(EntryPageSettingsInputData::from(['title' => 'Plain'])->toPageSettingsData()->recordPage);
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
