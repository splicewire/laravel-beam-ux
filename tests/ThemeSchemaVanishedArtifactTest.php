<?php

namespace Splicewire\Beam\Ux\Tests;

use ErrorException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

/**
 * Fix B (calcucrypt, root ruling 0bc3e9ce): the boot's stale-artifact sweep
 * ({@see \Splicewire\Beam\Ux\Concerns\WiresThemeSchemas::forgetStaleThemeArtifact()}) globs the artifact
 * directory and reads each `*.schema.json` it lists. A file the glob listed can be gone by the time it is
 * read: another process sharing the directory unlinked it in between, or the entry is a symlink whose target
 * is gone. Under Laravel's error handler that read's warning (`file_get_contents(...): Failed to open stream`)
 * was an ErrorException, and the boot — the request — failed. A file that is gone holds no stale `$id`, so the
 * sweep skips it.
 *
 * Deterministic stand-in for the race: a dangling `*.schema.json` symlink is exactly what a reader sees when
 * the glob lists an entry whose file is gone, and a stale stored root makes the sweep run at all (an
 * up-to-date root returns before the glob).
 *
 * Only a CONFIRMED vanished entry is skipped. Any other read failure (a file that is there but unreadable, a
 * directory under an artifact's name, any other I/O error) still fails loudly: it is a broken install, not this
 * race, and swallowing it would leave a stale root in place behind a frozen-schema conflict.
 */
class ThemeSchemaVanishedArtifactTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/beamux-fixb-'.uniqid();
        config(['beam.ux.scratch_storage_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Run $boot as Laravel's HandleExceptions does: error_reporting(-1), and an error handler that throws on any
     * warning not suppressed with @. PHPUnit lowers error_reporting() while a test runs, so without raising it the
     * handler would read the warning as @-suppressed and let it pass (the same harness as schemastud's
     * SchemaRegistryDirectoryRaceTest).
     */
    private function underLaravelsErrorHandling(callable $boot): void
    {
        $reporting = error_reporting(-1);
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (! (error_reporting() & $level)) {
                return false;
            }

            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $boot();
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }

    /**
     * A stale stored root (the previous declaration of `theme`), so the sweep has work to do.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: FilesystemSchemaRegistry} the directory, the current root, the registry
     */
    private function withAStaleRoot(): array
    {
        $dir = ThemeSchemas::directory();
        $this->assertStringStartsWith($this->root, $dir, 'the test must own its artifact directory');
        File::ensureDirectoryExists($dir);

        $current = ThemeSchemas::all();
        $root = end($current);
        $this->assertSame(ThemeSchemas::ROOT_ID, $root['$id']);
        $stale = $root;
        $stale['properties']['retired-namespace'] = ['type' => 'object'];
        $registry = new FilesystemSchemaRegistry($dir);
        $registry->register($stale);

        return [$dir, $root, $registry];
    }

    private function bootThemeSchemas(): void
    {
        $provider = $this->app->getProvider(BeamUxServiceProvider::class);
        $this->underLaravelsErrorHandling(fn () => (fn () => $this->registerThemeSchemas())->call($provider));
    }

    public function test_the_stale_sweep_still_fails_on_an_artifact_that_is_there_but_unreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a mode-000 file.');
        }
        [$dir] = $this->withAStaleRoot();

        $locked = $dir.'/theme-locked.0000000000000000.schema.json';
        file_put_contents($locked, '{"$id": "https://example.test/locked"}');
        chmod($locked, 0000);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('theme-locked.0000000000000000.schema.json');
            $this->bootThemeSchemas();
        } finally {
            chmod($locked, 0644);
        }
    }

    public function test_the_stale_sweep_still_fails_on_a_directory_under_an_artifacts_name(): void
    {
        [$dir] = $this->withAStaleRoot();
        mkdir($dir.'/theme-dir.0000000000000000.schema.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('theme-dir.0000000000000000.schema.json');
        $this->bootThemeSchemas();
    }

    public function test_the_stale_sweep_skips_an_artifact_that_is_gone_by_the_time_it_is_read(): void
    {
        [$dir, $root, $registry] = $this->withAStaleRoot();

        // An entry the glob lists whose file is gone.
        $gone = $dir.'/theme-gone.0000000000000000.schema.json';
        symlink($dir.'/vanished-by-another-host.json', $gone);
        $this->assertTrue(is_link($gone) && ! file_exists($gone), 'a dangling artifact entry');

        $this->bootThemeSchemas();

        // The stale root was swept and the current one registered; the vanished entry was skipped, not read.
        $this->assertSame(SchemaFingerprint::of($root), SchemaFingerprint::of($registry->get(ThemeSchemas::ROOT_ID)));
        $this->assertTrue(is_link($gone), 'the sweep leaves an entry it could not read where it was');
    }
}
