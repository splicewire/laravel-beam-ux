<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\BeamUxRetiredMigrationAudit;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * migration-flatten 06: every stub beam-ux retired is declared, a host's published copy of one is reported, and the
 * surviving creates are never condemned.
 */
class BeamUxRetiredMigrationAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/beam-ux-retired-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shared', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/shared/*.php') as $file) {
            is_string($file) && @unlink($file);
        }
        @rmdir($this->root.'/shared');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_every_retired_stub_is_absent_from_the_package_and_declared(): void
    {
        $shipped = array_map(fn ($p) => 'shared/'.basename($p, '.php.stub'), glob(dirname(__DIR__, 2).'/database/migrations/shared/*.php.stub') ?: []);
        $this->assertCount(12, BeamUxRetiredMigrationAudit::UX_RETIRED);
        foreach (array_keys(BeamUxRetiredMigrationAudit::UX_RETIRED) as $retired) {
            $this->assertNotContains($retired, $shipped, "{$retired} is declared retired but still ships");
        }
    }

    public function test_a_published_copy_of_a_folded_alter_warns_and_names_its_create(): void
    {
        touch($this->root.'/shared/2026_09_01_000000_create_beam_ux_entries_table.php');
        touch($this->root.'/shared/2026_09_01_000001_add_page_settings_to_beam_ux_entries_table.php');
        $warns = array_values(array_filter((new BeamUxRetiredMigrationAudit($this->root))->run(), fn ($f) => $f->status === DoctorStatus::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString('add_page_settings_to_beam_ux_entries_table', $warns[0]->detail);
        $this->assertStringContainsString('shared/create_beam_ux_entries_table', $warns[0]->detail);
    }

    public function test_a_host_with_only_the_creates_passes(): void
    {
        touch($this->root.'/shared/2026_09_01_000000_create_beam_ux_entries_table.php');
        touch($this->root.'/shared/2026_09_01_000001_create_beam_ux_releases_table.php');
        $findings = (new BeamUxRetiredMigrationAudit($this->root))->run();
        $this->assertSame([], array_values(array_filter($findings, fn ($f) => $f->status !== DoctorStatus::Pass)));
    }
}
