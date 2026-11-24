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
 *
 * The sweep decides what to remove from what the directory holds, then registers. Another process registering
 * between the two used to make this boot throw a frozen-schema conflict (or have its own artifact swept under
 * it). The sweep and the register now run inside the registry's {@see FilesystemSchemaRegistry::withLock()},
 * so for every other process they are one step. Checked with a second real process (pcntl_fork; skipped
 * without pcntl/posix) that holds the lock with the other side's root in place.
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

    public function test_the_boot_sweeps_and_registers_under_the_registry_lock(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are needed to run a second process.');
        }

        $dir = ThemeSchemas::directory();
        $this->assertStringStartsWith($this->root, $dir, 'the test must own its artifact directory');
        File::ensureDirectoryExists($dir);
        $signals = $this->root.'-signals';
        File::ensureDirectoryExists($signals);

        // The other side of a deploy: the same root $id in another shape.
        $current = ThemeSchemas::all();
        $root = end($current);
        $other = $root;
        $other['properties']['retired-namespace'] = ['type' => 'object'];

        // Every other theme schema is already stored in its current shape, as on any boot after the first: its
        // sweep and register return before the lock, so the root's sweep is the step that meets the other process.
        $registry = new FilesystemSchemaRegistry($dir);
        foreach ($current as $schema) {
            if ($schema['$id'] !== ThemeSchemas::ROOT_ID) {
                $registry->register($schema);
            }
        }

        $await = static function (string $file): void {
            $deadline = microtime(true) + 10;
            while (! is_file($file)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException("timed out waiting for [{$file}]");
                }
                usleep(5000);
            }
        };

        $child = pcntl_fork();
        if ($child === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($child === 0) {
            // The second process: it holds the lock, registers its root, and checks that root is still its own when
            // it lets go. A boot that honours the lock cannot sweep it in between.
            try {
                (new FilesystemSchemaRegistry($dir))->withLock(function (FilesystemSchemaRegistry $locked) use ($other, $signals, $await): void {
                    $locked->register($other);
                    touch($signals.'/second-holds');
                    $await($signals.'/boot-starting');
                    usleep(400_000); // the boot is now running: at the lock, or (without it) through the sweep
                    $stored = $locked->get(ThemeSchemas::ROOT_ID);
                    $kept = $stored !== null && SchemaFingerprint::of($stored) === SchemaFingerprint::of($other);
                    file_put_contents($signals.'/second-result', $kept ? 'kept' : 'swept under the lock');
                });
                file_put_contents($signals.'/second-released', sprintf('%.6f', microtime(true)));
            } catch (\Throwable $e) {
                file_put_contents($signals.'/second-result', 'error: '.$e->getMessage());
            }
            posix_kill(posix_getpid(), SIGKILL); // leave without running the parent's PHPUnit shutdown
        }

        try {
            $await($signals.'/second-holds');
            touch($signals.'/boot-starting');
            $this->bootThemeSchemas();
            $booted = microtime(true);

            pcntl_waitpid($child, $status);
            $child = 0;

            $this->assertSame('kept', (string) file_get_contents($signals.'/second-result'), 'the boot must not sweep an artifact while another process holds the registry lock');
            $this->assertGreaterThan((float) file_get_contents($signals.'/second-released'), $booted, 'the boot finished only after the lock was free');
            // Then the boot swept the other side's root and registered its own, without throwing.
            $this->assertSame(SchemaFingerprint::of($root), SchemaFingerprint::of($registry->get(ThemeSchemas::ROOT_ID)));
        } finally {
            if ($child > 0) {
                posix_kill($child, SIGKILL);
                pcntl_waitpid($child, $status);
            }
            File::deleteDirectory($signals);
        }
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
