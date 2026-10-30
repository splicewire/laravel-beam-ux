<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\File;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/**
 * landing-motion ticket 25 — the generated theme-schema artifact must be PER-HOST, not one directory
 * shared by every host linking the live `splicewire/laravel-beam-ux`. Evidence (primary):
 * `.scratch/rushing/audiostud/landing-motion/tickets/25-shared-theme-schema-artifact.md`,
 * `evidence/07-s9/harness-laravel-500.txt`, `evidence/08-k15/k15-full.laravel-500.txt` —
 * `file_get_contents(...theme.<id-hash>.schema.json): No such file` and `Refusing to overwrite frozen
 * schema "theme"` (stored fingerprint 856560d2d942 vs incoming 8fdb50b754dc).
 *
 * Deterministic stand-in: two concrete `theme` shapes and two host storage roots. The actual second
 * host's identity was unmeasured and is not fabricated here — only two shapes over two roots.
 */
class ThemeSchemaPerHostArtifactTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $shapeA = ['$id' => 'theme', 'type' => 'object', 'properties' => ['a' => ['type' => 'string']]];

    /** @var array<string, mixed> */
    private array $shapeB = ['$id' => 'theme', 'type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]];

    private string $rootA;

    private string $rootB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootA = sys_get_temp_dir().'/beamux-t25-hostA-'.uniqid();
        $this->rootB = sys_get_temp_dir().'/beamux-t25-hostB-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootA);
        File::deleteDirectory($this->rootB);
        parent::tearDown();
    }

    public function test_two_hosts_with_different_theme_shapes_do_not_share_one_artifact(): void
    {
        config(['beam.ux.scratch_storage_root' => $this->rootA]);
        $dirA = ThemeSchemas::directory();
        (new FilesystemSchemaRegistry($dirA))->register($this->shapeA);

        // A second host, a different theme shape. Before the fix, directory() was the one shared package
        // dir regardless of host, so this register() hit `theme`'s single file and threw
        // SchemaRegistryConflict (the "Refusing to overwrite frozen" 500). Per-host, it is isolated.
        config(['beam.ux.scratch_storage_root' => $this->rootB]);
        $dirB = ThemeSchemas::directory();
        (new FilesystemSchemaRegistry($dirB))->register($this->shapeB);

        $this->assertNotSame($dirA, $dirB, 'theme-schema directory must be per host, not one shared path');
        $this->assertEqualsCanonicalizing($this->shapeA, (new FilesystemSchemaRegistry($dirA))->get('theme'));
        $this->assertEqualsCanonicalizing($this->shapeB, (new FilesystemSchemaRegistry($dirB))->get('theme'));
    }

    public function test_the_per_host_frozen_schema_guard_is_unchanged(): void
    {
        // Within ONE host, re-registering `theme` with a different fingerprint must still be refused —
        // the fix isolates hosts, it does not weaken the immutability guard.
        config(['beam.ux.scratch_storage_root' => $this->rootA]);
        $dir = ThemeSchemas::directory();
        (new FilesystemSchemaRegistry($dir))->register($this->shapeA);

        $this->expectException(SchemaRegistryConflict::class);
        (new FilesystemSchemaRegistry($dir))->register($this->shapeB);
    }
}
