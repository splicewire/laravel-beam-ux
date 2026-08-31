<?php

namespace Splicewire\Beam\Ux\Tests;

/**
 * beam-docs-satellite 51 → 53. `beam.ux.compile.binary` defaults to a bare `node`, and the CLI and
 * php-fpm do not share a PATH — so every browser-driven save on a Herd host compiled nothing and
 * returned 200 while every CLI measurement this map ever took stayed green.
 *
 * ⚠️ **None of these tests can see that defect**, and saying so is the point: they run from the CLI,
 * which is the side that already works. What they hold is the pin itself — that the value written is
 * absolute, quoted, verified against a real `--version`, and never silently repointed over an
 * operator's own. The defect's own acceptance is an HTTP save that produces a new artifact on disk.
 */
class PinNodeCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-ux-pin-node-'.getmypid().'-'.spl_object_id($this);
        @mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);

        parent::tearDown();
    }

    public function test_it_pins_an_absolute_path_and_is_idempotent(): void
    {
        file_put_contents("{$this->root}/.env", "APP_ENV=local\n");

        $binary = $this->fakeNode();

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();

        $this->assertStringContainsString('BEAM_UX_NODE_BINARY="'.$binary.'"', $this->env());

        // A second run writes nothing new — the key is present with the same value.
        $before = $this->env();
        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();
        $this->assertSame($before, $this->env());
    }

    /**
     * ⚠️ The real resolved path on a Herd machine is
     * `…/Library/Application Support/Herd/…/bin/node`. An unquoted space makes Dotenv read the value as
     * `…/Application` and drop the rest — failing exactly like the unpinned default it repairs, which
     * is the kind of near-miss that reads as a working fix.
     */
    public function test_the_written_value_is_quoted_so_a_path_with_spaces_survives(): void
    {
        file_put_contents("{$this->root}/.env", "APP_ENV=local\n");

        $binary = $this->fakeNode('a dir with spaces');

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();

        $this->assertStringContainsString('="'.$binary.'"', $this->env());
        $this->assertSame($binary, $this->parsedValue());
    }

    public function test_an_operator_set_value_survives_without_force(): void
    {
        file_put_contents("{$this->root}/.env", "BEAM_UX_NODE_BINARY=/opt/mine/node\n");

        $binary = $this->fakeNode();

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();
        $this->assertStringContainsString('/opt/mine/node', $this->env());
        $this->assertStringNotContainsString($binary, $this->env());

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary, '--force' => true])
            ->assertSuccessful();
        $this->assertStringContainsString('BEAM_UX_NODE_BINARY="'.$binary.'"', $this->env());
        $this->assertStringNotContainsString('/opt/mine/node', $this->env());
    }

    /**
     * A path that does not answer `--version` is refused rather than pinned. Writing an unverified
     * string would reproduce the original defect with a longer value in it.
     */
    public function test_it_refuses_a_binary_that_does_not_answer_version(): void
    {
        file_put_contents("{$this->root}/.env", "APP_ENV=local\n");

        $this->artisan('splicewire:beam:ux:pin-node', [
            '--path' => $this->root,
            '--binary' => "{$this->root}/not-a-binary",
        ])->assertFailed();

        $this->assertStringNotContainsString('BEAM_UX_NODE_BINARY', $this->env());
    }

    public function test_a_host_with_no_env_file_is_a_no_op_not_a_failure(): void
    {
        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root])->assertSuccessful();

        $this->assertFileDoesNotExist("{$this->root}/.env");
    }

    private function rmrf(string $dir): void
    {
        foreach ((array) glob("{$dir}/*") as $path) {
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }

        @unlink("{$dir}/.env");
        @rmdir($dir);
    }

    /** A shell stub that answers `--version`, standing in for a real Node so the test needs none. */
    private function fakeNode(string $subdir = 'bin'): string
    {
        $dir = "{$this->root}/{$subdir}";
        @mkdir($dir, 0777, true);

        $path = "{$dir}/node";
        file_put_contents($path, "#!/bin/sh\necho v22.13.0\n");
        chmod($path, 0755);

        return $path;
    }

    private function env(): string
    {
        return is_file("{$this->root}/.env") ? (string) file_get_contents("{$this->root}/.env") : '';
    }

    /** The value as a dotenv parser reads it — the check the quoting exists for. */
    private function parsedValue(): string
    {
        $parsed = \Dotenv\Dotenv::parse($this->env());

        return (string) ($parsed['BEAM_UX_NODE_BINARY'] ?? '');
    }
}
