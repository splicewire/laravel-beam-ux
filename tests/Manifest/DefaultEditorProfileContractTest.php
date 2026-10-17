<?php

namespace Splicewire\Beam\Ux\Tests\Manifest;

use Splicewire\Beam\Ux\Manifest\HtmlManifestProfile;
use Splicewire\Beam\Ux\Manifest\PackagedEditorNodeSource;
use Splicewire\Beam\Ux\Tests\TestCase;

class DefaultEditorProfileContractTest extends TestCase
{
    public function test_committed_profile_matches_the_php_declarations(): void
    {
        $profile = HtmlManifestProfile::manifest();
        $profile['nodes'] = array_merge($profile['nodes'], (new PackagedEditorNodeSource)->manifest('content')['nodes']);
        $expected = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $this->assertSame($expected, file_get_contents(__DIR__.'/../../resources/manifests/default-editor-profile.json'));
        // Paired PHP/JS contract gate: point at the consumer's committed fixture. An explicit
        // missing path fails; never substitute a freshly generated file for the consumer copy.
        if ($consumer = getenv('EP_EDITOR_PROFILE_FIXTURE')) {
            $this->assertFileExists($consumer);
            $this->assertSame($expected, file_get_contents($consumer), 'Beam JS profile fixture is stale');
        }
    }
}
