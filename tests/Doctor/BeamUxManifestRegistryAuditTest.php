<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\BeamUxManifestRegistryAudit;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Manifest\Fixtures\OperatorNodeSource;
use Splicewire\Beam\Ux\Tests\TestCase;

/** EP-07 (ADR-0228 d6): each realm's Manifest Profile against the host's JS registry snapshot. */
class BeamUxManifestRegistryAuditTest extends TestCase
{
    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = sys_get_temp_dir().'/beam-js-registry-'.uniqid().'.json';
        config(['fortify.features' => []]);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshot);
        parent::tearDown();
    }

    /** @return list<string> the realm profile's component names, as a host's snapshot would list them */
    private function components(string $realm): array
    {
        $names = array_column(app(ManifestProfileResolver::class)->resolve(new BeamUxEntry(['type' => 'page', 'realm' => $realm]))['nodes'], 'name');

        return array_values(array_filter($names, fn (string $name) => ctype_upper($name[0])));
    }

    public function test_without_a_snapshot_it_is_inconclusive_and_never_a_pass(): void
    {
        // WP-EP07 ruling: an unmeasured comparison must not count as a pass in any summary or gate.
        $findings = (new BeamUxManifestRegistryAudit)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringStartsWith('INCONCLUSIVE — no JS registry snapshot is configured', $findings[0]->detail);

        config(['beam.ux.manifest.js_registry' => $this->snapshot]);
        $this->assertStringContainsString('does not exist', (new BeamUxManifestRegistryAudit)->run()[0]->detail);
    }

    public function test_it_passes_when_the_registry_matches_and_fails_on_drift_both_ways(): void
    {
        config(['beam.ux.manifest.js_registry' => $this->snapshot]);
        $components = $this->components('site');
        $this->assertContains('AuthLogin', $components, 'the shipped auth islands are part of the compared vocabulary');
        file_put_contents($this->snapshot, json_encode($components));

        $this->assertSame(DoctorStatus::Pass, (new BeamUxManifestRegistryAudit)->run()[0]->status);

        file_put_contents($this->snapshot, json_encode([...array_diff($components, ['AuthLogin']), 'Ghost']));
        $drift = (new BeamUxManifestRegistryAudit)->run()[0];
        $this->assertSame(DoctorStatus::Fail, $drift->status);
        $this->assertStringContainsString('not registered in JS: [AuthLogin]', $drift->detail);
        $this->assertStringContainsString('not declared in the profile: [Ghost]', $drift->detail);
    }

    public function test_where_mdx_pages_hold_blocks_the_mdx_kit_is_part_of_the_compared_vocabulary(): void
    {
        // ADR-0233 d7: an MDX page that holds blocks is checked against the beam-mdx kit too, so the one JS registry a
        // host registers must carry the kit, and the comparison must count it (starter receipt 2026-10-25).
        config(['beam.ux.manifest.js_registry' => $this->snapshot, 'beam.ux.mdx.holds_blocks' => true]);
        $site = $this->components('site');
        $kit = array_column((new \Splicewire\Beam\Mdx\Kit\MdxKitNodeSource)->manifest('content')['nodes'], 'name');
        $this->assertContains('Callout', $kit);
        // EP-42 S1: and the Code Components packages' shipped MDX pages place (SiteNav, ManifestTable).
        $shipped = array_column((new \Splicewire\Beam\Ux\Manifest\SiteNodeSource)->manifest('content')['nodes'], 'name');

        file_put_contents($this->snapshot, json_encode([...$site, ...$kit, ...$shipped]));
        $this->assertSame(DoctorStatus::Pass, (new BeamUxManifestRegistryAudit)->run()[0]->status, 'the kit a host registers is declared');

        file_put_contents($this->snapshot, json_encode($site));
        $missing = (new BeamUxManifestRegistryAudit)->run()[0];
        $this->assertSame(DoctorStatus::Fail, $missing->status);
        $this->assertStringContainsString('Callout', $missing->detail, 'a kit the canvas cannot draw is drift');
    }

    public function test_a_per_realm_snapshot_is_compared_realm_by_realm(): void
    {
        config(['beam.ux.manifest.js_registry' => $this->snapshot, 'beam.ux.manifest.realms' => ['operator' => OperatorNodeSource::class]]);
        file_put_contents($this->snapshot, json_encode(['site' => $this->components('site'), 'operator' => $this->components('site')]));

        $findings = collect((new BeamUxManifestRegistryAudit)->run())->keyBy('check');

        $this->assertSame(DoctorStatus::Pass, $findings['Manifest Profile vs JS registry (site)']->status);
        $operator = $findings['Manifest Profile vs JS registry (operator)'];
        $this->assertSame(DoctorStatus::Fail, $operator->status);
        $this->assertStringContainsString('not registered in JS: [Banner]', $operator->detail);

        file_put_contents($this->snapshot, json_encode(['site' => $this->components('site')]));
        $missing = collect((new BeamUxManifestRegistryAudit)->run())->keyBy('check')['Manifest Profile vs JS registry (operator)'];
        $this->assertSame(DoctorStatus::Warn, $missing->status);
        $this->assertFalse($missing->conclusive);
    }
}
