<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Nav\NavSource;

/**
 * beam-install-turnkey trap 2: `NavSource` resolves a disk `nav.{yml,yaml,json}` relative to the git-tracked
 * mirror-disk root when configured, else `resource_path('beam-ux')` — the documented author-source dir — so a
 * fresh host that drops `resources/beam-ux/nav.yml` is found with ZERO config. (It was `base_path()` before,
 * which never matched the documented location, so `ux:seed-nav` silently found nothing.)
 */
class NavSourceRootTest extends TestCase
{
    private string $resourceNavDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourceNavDir = resource_path('beam-ux');
    }

    protected function tearDown(): void
    {
        foreach (['yml', 'yaml', 'json'] as $ext) {
            @unlink($this->resourceNavDir.'/nav.'.$ext);
        }
        @rmdir($this->resourceNavDir);

        parent::tearDown();
    }

    public function test_a_nav_yml_in_resource_beam_ux_is_found_with_no_config(): void
    {
        // No mirror_disk configured (the default fresh-host state).
        config()->set('beam.ux.storage.mirror_disk', null);
        config()->set('beam.ux.nav', null);

        if (! is_dir($this->resourceNavDir)) {
            mkdir($this->resourceNavDir, 0777, true);
        }

        file_put_contents(
            $this->resourceNavDir.'/nav.yml',
            "home:\n  segment: '/'\n  title: 'Home'\n  type: 'page'\n  realm: 'site'\n",
        );

        $rows = (new NavSource)->resolve('');

        $this->assertNotEmpty($rows, 'nav.yml under resource_path(beam-ux) should be discovered with no host config');
        $this->assertSame('home', $rows[0]['slug']);
        $this->assertSame('/', $rows[0]['segment']);
        $this->assertSame('Home', $rows[0]['title']);
    }

    public function test_without_a_nav_file_the_source_reports_derived(): void
    {
        config()->set('beam.ux.storage.mirror_disk', null);
        config()->set('beam.ux.nav', null);

        // Ensure no stray nav file under the resource dir.
        foreach (['yml', 'yaml', 'json'] as $ext) {
            @unlink($this->resourceNavDir.'/nav.'.$ext);
        }

        $this->assertTrue((new NavSource)->isDerived(''), 'with no config + no disk file the nav DERIVES from entry frontmatter');
    }
}
