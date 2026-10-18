<?php

namespace Splicewire\Beam\Ux\Tests;

class PageSettingsContractTest extends TestCase
{
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
