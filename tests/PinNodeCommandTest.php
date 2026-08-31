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
        // ⚠️ The incumbent must be a WORKING absolute path, or this test asserts the wrong thing: a
        // value that cannot answer `--version` is now replaced without `--force` on purpose, so a
        // fictional `/opt/mine/node` here would make this test pass for the opposite reason.
        $mine = $this->fakeNode('mine');
        file_put_contents("{$this->root}/.env", "BEAM_UX_NODE_BINARY=\"{$mine}\"\n");

        $binary = $this->fakeNode();

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();
        $this->assertStringContainsString($mine, $this->env());
        $this->assertStringNotContainsString($binary, $this->env());

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary, '--force' => true])
            ->assertSuccessful();
        $this->assertSame($binary, $this->parsedValue());
        $this->assertStringNotContainsString($mine, $this->env());
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

    /**
     * ⚠️ A filesystem path is arbitrary text, and `preg_replace` reads `$1` in its REPLACEMENT as a
     * backreference. The first version of this command spliced the path in with `preg_replace` and
     * silently wrote a DIFFERENT path — `/opt/n$1ode/bin/node` landed as `/opt/node/bin/node`. It is
     * the same near-miss as an unquoted space: a value that looks pinned and is wrong.
     */
    public function test_a_path_containing_regex_metacharacters_is_written_verbatim(): void
    {
        file_put_contents("{$this->root}/.env", "BEAM_UX_NODE_BINARY=stale\n");

        $binary = $this->fakeNode('n$1ode\\dir');

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($binary, $this->parsedValue());
        $this->assertStringNotContainsString('stale', $this->env());
    }

    /**
     * The install path's whole promise is "a fresh host is born correct". Guarding on the key's mere
     * PRESENCE broke that at the one host that needed it: a `.env` already carrying the broken bare
     * `node` this ticket exists to repair was warned about and skipped. An incumbent that cannot answer
     * `--version` is not a choice an operator made.
     */
    public function test_an_incumbent_that_does_not_run_is_replaced_without_force(): void
    {
        // The specimen is the literal bare `node` this ticket exists to repair — and it ANSWERS
        // `--version` from a test process, which is why absoluteness and not runnability is the guard.
        file_put_contents("{$this->root}/.env", "BEAM_UX_NODE_BINARY=node\n");

        $binary = $this->fakeNode();

        $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root, '--binary' => $binary])
            ->assertSuccessful();

        $this->assertSame($binary, $this->parsedValue());
    }

    /**
     * A RESOLVED binary that does not run is a fact about the host, and this estate's rule is that a
     * host fact is advisory — `splicewire:beam:install` calls this command and must not be refused by
     * one machine's broken Node. An operator-NAMED `--binary` stays fatal (the test above), because
     * that is something its author could have gotten right.
     */
    public function test_a_broken_resolved_binary_warns_and_succeeds_rather_than_failing_the_install(): void
    {
        file_put_contents("{$this->root}/.env", "APP_ENV=local\n");

        $broken = "{$this->root}/bin/node";
        @mkdir("{$this->root}/bin", 0777, true);
        file_put_contents($broken, "#!/bin/sh\nexit 1\n");
        chmod($broken, 0755);

        // Resolution is stubbed by pointing PATH at the broken stub, so no `--binary` is named.
        $original = getenv('PATH');
        putenv("PATH={$this->root}/bin");

        try {
            $this->artisan('splicewire:beam:ux:pin-node', ['--path' => $this->root])->assertSuccessful();
        } finally {
            putenv("PATH={$original}");
        }

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
