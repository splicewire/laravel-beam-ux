<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Ux\Manifest\HtmlManifestProfile;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;

class ProseMetadataTest extends TestCase
{
    public function test_prose_roles_are_editor_metadata_not_admission_rules(): void
    {
        $profile = HtmlManifestProfile::manifest();
        $this->assertSame('block', $profile['x-prose']['p']);
        foreach (['strong', 'em', 'a', 'code'] as $name) {
            $this->assertSame('mark', $profile['x-prose'][$name]);
        }
        $this->assertSame('cell', $profile['x-prose']['td']);
        $this->assertArrayNotHasKey('div', $profile['x-prose']);
        $body = [['kind' => 'block', 'name' => 'p', 'id' => 'p', 'props' => [], 'children' => [
            ['kind' => 'block', 'name' => 'strong', 'id' => 's', 'props' => [], 'children' => [['kind' => 'text', 'value' => 'hello']]],
            ['kind' => 'block', 'name' => 'Unknown', 'id' => 'u', 'props' => [], 'children' => []],
        ]]];
        $without = $profile;
        unset($without['x-prose']);
        $this->assertSame((new ProfileLegality)->violations($body, $without), (new ProfileLegality)->violations($body, $profile));
    }

    public function test_resolved_profile_carries_prose_without_opting_in_components(): void
    {
        $profile = app(ManifestProfileResolver::class)->resolve(new BeamUxEntry);
        $this->assertSame(HtmlManifestProfile::manifest()['x-prose'], $profile['x-prose']);
        $this->assertArrayNotHasKey('DemoHero', $profile['x-prose']);
        $this->assertArrayNotHasKey('DemoFeatureRow', $profile['x-prose']);
    }
}
