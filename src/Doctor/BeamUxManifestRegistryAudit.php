<?php

namespace Splicewire\Beam\Ux\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ManifestRegistryCheck;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0228 d6 (EP-07) — a host's placeable components exist twice: as the `#[NodeType]` nodes of each realm's Manifest
 * Profile, which types the save, and as the React components the canvas registers, which draws them. This compares
 * the two both ways ({@see ManifestRegistryCheck}) for every realm the host binds (`beam.ux.manifest.realms`, plus the
 * default `site`), against the snapshot of the JS registry the host's build writes (`beam.ux.manifest.js_registry`:
 * a JSON list of names, or realm => list). Drift FAILS: a component the save admits that the canvas cannot draw, or
 * one the canvas draws that the save refuses.
 *
 * Without a snapshot nothing was compared. That is reported INCONCLUSIVE as a **warning**, flagged unmeasured, and
 * never as a pass in any summary or gate (WP-EP07 ruling, 2026-10-25), so a host that has not wired its snapshot sees
 * the gap instead of a green line.
 */
class BeamUxManifestRegistryAudit implements DoctorAudit
{
    private const CHECK = 'Manifest Profile vs JS registry';

    /** @return list<Finding> */
    public function run(): array
    {
        $path = config('beam.ux.manifest.js_registry');
        if (! is_string($path) || $path === '') {
            return [self::inconclusive('no JS registry snapshot is configured (beam.ux.manifest.js_registry), so no realm profile was compared with the components the canvas registers.')];
        }
        if (! is_file($path)) {
            return [self::inconclusive("the JS registry snapshot [{$path}] does not exist; run the host's build that writes it.")];
        }
        $snapshot = json_decode((string) file_get_contents($path), true);
        if (! is_array($snapshot)) {
            return [self::inconclusive("the JS registry snapshot [{$path}] is not JSON.")];
        }

        $findings = [];
        $perRealm = ! array_is_list($snapshot);
        foreach ($this->realms($perRealm ? array_keys($snapshot) : []) as $realm) {
            $js = $perRealm ? ($snapshot[$realm] ?? null) : $snapshot;
            if (! is_array($js)) {
                $findings[] = self::inconclusive("the snapshot names no registry for the [{$realm}] realm.", " ({$realm})");

                continue;
            }
            $drift = (new ManifestRegistryCheck)->drift($this->components($realm), array_values(array_map('strval', $js)));
            $findings[] = $drift['missingInJs'] === [] && $drift['missingInPhp'] === []
                ? Finding::pass(self::CHECK." ({$realm})", 'the profile and the JS registry declare the same components.')
                : Finding::fail(self::CHECK." ({$realm})", 'declared in the profile but not registered in JS: ['.implode(', ', $drift['missingInJs'])
                    .']; registered in JS but not declared in the profile: ['.implode(', ', $drift['missingInPhp']).'].');
        }

        return $findings;
    }

    /**
     * The realms to compare: the ones the host binds, the default site realm, and any the snapshot names.
     *
     * @param  list<string>  $named
     * @return list<string>
     */
    private function realms(array $named): array
    {
        return array_values(array_unique([BeamUxEntry::REALM_SITE, ...array_keys((array) config('beam.ux.manifest.realms', [])), ...$named]));
    }

    /**
     * A realm profile's components: its node names that are JSX components (capitalised), not the HTML base elements.
     *
     * @return list<string>
     */
    private function components(string $realm): array
    {
        // One JS registry serves every page of the realm: where MDX pages hold blocks, their profile adds the beam-mdx
        // kit (ADR-0233 d7), so the compared vocabulary is the union of the TSX and the MDX page profiles.
        $probes = [new BeamUxEntry(['type' => 'page', 'realm' => $realm])];
        if (config('beam.ux.mdx.holds_blocks')) {
            $probes[] = new BeamUxEntry(['type' => 'page', 'realm' => $realm, 'format' => 'mdx']);
        }
        $names = [];
        foreach ($probes as $probe) {
            $names = [...$names, ...array_column(app(ManifestProfileResolver::class)->resolve($probe)['nodes'], 'name')];
        }

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '' && ctype_upper($name[0]))));
    }

    private static function inconclusive(string $detail, string $realm = ''): Finding
    {
        return new Finding(DoctorStatus::Warn, self::CHECK.$realm, 'INCONCLUSIVE — '.$detail, conclusive: false);
    }
}
