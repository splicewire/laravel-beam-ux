<?php

namespace Splicewire\Beam\Ux\Tests;

/**
 * beam-install-turnkey (pnpm-host trap): `splicewire:beam:ux:pnpm-overrides` walks the `@splicewire/@schemastud`
 * packages a host `file:`-links, follows THEIR dep graph, and pins every unpublished transitive name to
 * its local `file:` path — so a pnpm host resolves the surface set without hitting `ERR_PNPM_FETCH_404`.
 */
class PnpmOverridesCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/beam-pnpm-'.uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmp);
        parent::tearDown();
    }

    /** Lay out …/js/packages/<org>/<pkg>/package.json + a host root that file:-links two surfaces. */
    private function scaffold(bool $pnpm = true): string
    {
        $pkgRoot = "{$this->tmp}/js/packages";
        $mk = function (string $rel, array $json) use ($pkgRoot): void {
            @mkdir("{$pkgRoot}/{$rel}", 0777, true);
            file_put_contents("{$pkgRoot}/{$rel}/package.json", json_encode($json));
        };
        // mainframe → frame-remote → seam (the unpublished transitive chain), + an unrelated local pkg.
        $mk('schemastud/mainframe', ['name' => '@schemastud/mainframe', 'dependencies' => ['@schemastud/frame-remote' => '*', 'react' => '*']]);
        $mk('schemastud/frame-remote', ['name' => '@schemastud/frame-remote', 'dependencies' => ['@schemastud/seam' => '*']]);
        $mk('schemastud/seam', ['name' => '@schemastud/seam']);
        $mk('schemastud/ui', ['name' => '@schemastud/ui']);
        $mk('beam/beam-ux', ['name' => '@splicewire/beam-ux', 'peerDependencies' => ['@schemastud/mainframe' => '*']]);

        $host = "{$this->tmp}/host";
        @mkdir($host, 0777, true);
        file_put_contents("{$host}/package.json", json_encode([
            'name' => 'host',
            'dependencies' => [
                '@splicewire/beam-ux' => 'file:../js/packages/beam/beam-ux',
                '@schemastud/mainframe' => 'file:../js/packages/schemastud/mainframe',
                '@schemastud/ui' => 'file:../js/packages/schemastud/ui',
            ],
        ], JSON_PRETTY_PRINT));
        if ($pnpm) {
            file_put_contents("{$host}/pnpm-lock.yaml", "lockfileVersion: '9.0'\n");
        }

        return $host;
    }

    public function test_pins_unpublished_transitive_deps_but_not_direct_ones(): void
    {
        $host = $this->scaffold();

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $overrides = $pkg['pnpm']['overrides'];

        // frame-remote + seam are transitive-and-unpublished → pinned.
        $this->assertArrayHasKey('@schemastud/frame-remote', $overrides);
        $this->assertArrayHasKey('@schemastud/seam', $overrides);
        $this->assertStringStartsWith('file:', $overrides['@schemastud/seam']);
        // mainframe + ui are already DIRECT deps → NOT re-pinned; react is out of scope.
        $this->assertArrayNotHasKey('@schemastud/mainframe', $overrides);
        $this->assertArrayNotHasKey('@schemastud/ui', $overrides);
        $this->assertArrayNotHasKey('react', $overrides);
    }

    public function test_is_a_noop_on_a_non_pnpm_host(): void
    {
        $host = $this->scaffold(pnpm: false);

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $this->assertArrayNotHasKey('pnpm', $pkg);
    }

    public function test_is_idempotent(): void
    {
        $host = $this->scaffold();
        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();
        $first = file_get_contents("{$host}/package.json");
        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();
        $this->assertSame($first, file_get_contents("{$host}/package.json"));
    }

    /**
     * beam-install-turnkey ticket 12: safe-unless-force, matching `vendor:publish` — a host that hand-edits
     * an override entry (e.g. to point at a different local checkout) keeps that edit on a routine run;
     * only `--force` lets the freshly computed value win.
     */
    public function test_preserves_a_hand_edited_override_entry_without_force(): void
    {
        $host = $this->scaffold();
        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $pkg['pnpm']['overrides']['@schemastud/seam'] = 'file:../custom/seam-path';
        file_put_contents("{$host}/package.json", json_encode($pkg, JSON_PRETTY_PRINT));

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $this->assertSame('file:../custom/seam-path', $pkg['pnpm']['overrides']['@schemastud/seam']);
        // A name with no existing entry is still added even without --force.
        $this->assertArrayHasKey('@schemastud/frame-remote', $pkg['pnpm']['overrides']);
    }

    public function test_force_overwrites_a_hand_edited_override_entry(): void
    {
        $host = $this->scaffold();
        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $computed = $pkg['pnpm']['overrides']['@schemastud/seam'];
        $pkg['pnpm']['overrides']['@schemastud/seam'] = 'file:../custom/seam-path';
        file_put_contents("{$host}/package.json", json_encode($pkg, JSON_PRETTY_PRINT));

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host, '--force' => true])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $this->assertSame($computed, $pkg['pnpm']['overrides']['@schemastud/seam']);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "{$dir}/{$f}";
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
