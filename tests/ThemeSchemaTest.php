<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/**
 * Ticket 01 (theme-entries-and-authoring): the namespaced `theme.canvas` / `theme.shell` /
 * `theme.site` schemas, package-shipped via a {@see FilesystemSchemaRegistry} tier and
 * `$ref`-composed under a root `theme` schema. Modeled directly on `splicewire/laravel-beam`'s
 * own `Splicewire\Beam\Tests\Schema\BeamSchemaRegistryTest` — same db-shadows-file proof, scoped
 * to `theme.site`.
 */
class ThemeSchemaTest extends TestCase
{
    private function createDbTier(): void
    {
        if (Schema::hasTable(Beam::table('schemas'))) {
            return;
        }

        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name')->nullable()->index();
            $table->integer('version')->nullable();
            $table->string('fingerprint');
            $table->string('artifact');
            $table->timestamps();
        });
    }

    /** The real `['file']` tier the package boots into (populated by `BeamUxServiceProvider::packageBooted()`). */
    private function fileRegistry(): BeamSchemaRegistry
    {
        return new BeamSchemaRegistry(
            ['file'],
            ['file' => fn () => new FilesystemSchemaRegistry(ThemeSchemas::directory())],
        );
    }

    private function dbFileRegistry(): BeamSchemaRegistry
    {
        return new BeamSchemaRegistry(
            ['db', 'file'],
            [
                'db' => fn () => new DatabaseSchemaRegistry,
                'file' => fn () => new FilesystemSchemaRegistry(ThemeSchemas::directory()),
            ],
        );
    }

    public function test_all_four_theme_schemas_are_registered_in_the_file_tier(): void
    {
        $registry = $this->fileRegistry();

        foreach ([ThemeSchemas::CANVAS_ID, ThemeSchemas::SHELL_ID, ThemeSchemas::SITE_ID, ThemeSchemas::ROOT_ID] as $id) {
            $this->assertTrue($registry->has($id), "Expected {$id} to resolve through the package's filesystem tier.");
            $this->assertNotNull($registry->get($id));
        }
    }

    public function test_canvas_schema_matches_the_eleven_key_canvas_theme_shape(): void
    {
        $schema = $this->fileRegistry()->get(ThemeSchemas::CANVAS_ID);

        $this->assertSame(ThemeSchemas::CANVAS_ID, $schema['$id']);
        $this->assertCount(11, $schema['properties']);
        $this->assertSame('#4F7CFF', $schema['properties']['accent']['default']);
        $this->assertSame('system-ui, sans-serif', $schema['properties']['fontBody']['default']);
    }

    public function test_shell_schema_has_ten_shell_custom_properties(): void
    {
        $schema = $this->fileRegistry()->get(ThemeSchemas::SHELL_ID);

        $this->assertSame(ThemeSchemas::SHELL_ID, $schema['$id']);
        $this->assertCount(10, $schema['properties']);
        $this->assertSame('#f4f4f5', $schema['properties']['surface']['default']);
    }

    public function test_root_theme_schema_ref_composes_all_three_namespaces(): void
    {
        $schema = $this->fileRegistry()->get(ThemeSchemas::ROOT_ID);

        $this->assertSame(ThemeSchemas::CANVAS_ID, $schema['properties']['canvas']['$ref']);
        $this->assertSame(ThemeSchemas::SHELL_ID, $schema['properties']['shell']['$ref']);
        $this->assertSame(ThemeSchemas::SITE_ID, $schema['properties']['site']['$ref']);
    }

    public function test_db_tier_registration_of_theme_site_shadows_only_that_namespace(): void
    {
        $this->createDbTier();

        (new DatabaseSchemaRegistry)->register([
            '$id' => ThemeSchemas::SITE_ID,
            'type' => 'object',
            'x-marker' => 'tenant-override',
        ]);

        $registry = $this->dbFileRegistry();

        $this->assertSame('tenant-override', $registry->get(ThemeSchemas::SITE_ID)['x-marker'] ?? null);

        // canvas/shell are untouched in the db tier — both still fall through to the package default.
        $this->assertSame(ThemeSchemas::CANVAS_ID, $registry->get(ThemeSchemas::CANVAS_ID)['$id']);
        $this->assertArrayNotHasKey('x-marker', $registry->get(ThemeSchemas::CANVAS_ID));
        $this->assertSame(ThemeSchemas::SHELL_ID, $registry->get(ThemeSchemas::SHELL_ID)['$id']);
        $this->assertArrayNotHasKey('x-marker', $registry->get(ThemeSchemas::SHELL_ID));
    }
}
