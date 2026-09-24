<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
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

    public function test_site_schema_carries_a_dark_counterpart_for_every_colour_slot(): void
    {
        $properties = $this->fileRegistry()->get(ThemeSchemas::SITE_ID)['properties'];

        foreach (['background', 'foreground', 'muted', 'accent', 'accentHover', 'accentForeground', 'border'] as $slot) {
            $dark = 'dark'.ucfirst($slot);

            $this->assertArrayHasKey($slot, $properties);
            $this->assertArrayHasKey($dark, $properties, "{$slot} has no dark counterpart");
            $this->assertSame('color', $properties[$dark]['format'], "{$dark} renders as a colour field");
            $this->assertNotSame($properties[$slot]['default'], $properties[$dark]['default'], "{$dark} repeats the light default");
        }
    }

    public function test_site_dark_defaults_read_as_a_dark_scheme(): void
    {
        $properties = ThemeSchemas::site()['properties'];

        // A dark page under light ink, and a label on the (lighter) dark accent that is dark itself —
        // `#fff` on a lifted accent was the unreadable pairing.
        $this->assertLessThan(0.1, $this->luminance($properties['darkBackground']['default']));
        $this->assertGreaterThan(0.6, $this->luminance($properties['darkForeground']['default']));
        $this->assertLessThan(
            $this->luminance($properties['darkAccent']['default']),
            $this->luminance($properties['darkAccentForeground']['default']),
        );
        $this->assertSame('#FFFFFF', $properties['accentForeground']['default']);
    }

    /** Relative luminance of a `#rrggbb` colour (WCAG). */
    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public function test_boot_regenerates_a_theme_artifact_left_by_an_older_declaration(): void
    {
        // The artifact directory is shared by every host that links this package. An artifact written
        // by the previous shape of `theme.site` made `register()` refuse the new one, and the boot threw.
        $registry = new FilesystemSchemaRegistry(ThemeSchemas::directory());
        $older = ThemeSchemas::site();
        unset($older['properties']['darkBackground']);

        foreach (glob(ThemeSchemas::directory().'/theme-site.*.schema.json') ?: [] as $path) {
            unlink($path);
        }
        $registry->register($older);
        $this->assertArrayNotHasKey('darkBackground', $registry->get(ThemeSchemas::SITE_ID)['properties']);

        $provider = $this->app->getProvider(BeamUxServiceProvider::class);
        (fn () => $this->registerThemeSchemas())->call($provider);

        $this->assertArrayHasKey('darkBackground', $registry->get(ThemeSchemas::SITE_ID)['properties']);
        $this->assertCount(1, glob(ThemeSchemas::directory().'/theme-site.*.schema.json') ?: []);
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
