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

    /**
     * A direct `file:` link does NOT exempt a name from needing an override — this test asserted the
     * opposite, and `splicewire/www` sat with a `pnpm install` that could not complete because of it
     * (beam-docs-satellite ticket 08). pnpm resolves a nested package's own semver range independently of
     * what the host links, so `@schemastud/frame` asking for `@schemastud/facets: ^0.1.0` went to the
     * registry and 404'd while the host linked that exact package from disk one line above.
     *
     * A direct REGISTRY spec is still exempt, and that is the distinction the old assertion was reaching
     * for: pinning one to a local path would silently overrule a host that deliberately asked for a
     * published version.
     */
    public function test_pins_unpublished_transitive_deps_including_ones_the_host_links_directly(): void
    {
        $host = $this->scaffold();

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $overrides = $pkg['pnpm']['overrides'];

        // frame-remote + seam are transitive-and-unpublished → pinned.
        $this->assertArrayHasKey('@schemastud/frame-remote', $overrides);
        $this->assertArrayHasKey('@schemastud/seam', $overrides);
        $this->assertStringStartsWith('file:', $overrides['@schemastud/seam']);

        // mainframe + ui are direct `file:` links AND transitively required — pinned anyway, so the
        // nested range resolves to the same local copy instead of the registry.
        $this->assertArrayHasKey('@schemastud/mainframe', $overrides);
        $this->assertStringStartsWith('file:', $overrides['@schemastud/mainframe']);

        $this->assertArrayNotHasKey('react', $overrides);
    }

    /** A name the host asks for by published version is the host's call, and an override would overrule it. */
    public function test_a_direct_registry_spec_is_never_overridden_to_a_local_path(): void
    {
        $host = $this->scaffold();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);
        $pkg['dependencies']['@schemastud/mainframe'] = '^2.0.0';
        file_put_contents("{$host}/package.json", json_encode($pkg, JSON_PRETTY_PRINT));

        $this->artisan('splicewire:beam:ux:pnpm-overrides', ['--path' => $host])->assertSuccessful();

        $pkg = json_decode((string) file_get_contents("{$host}/package.json"), true);

        $this->assertArrayNotHasKey('@schemastud/mainframe', $pkg['pnpm']['overrides']);
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
