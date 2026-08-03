<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Codec\JsonBodyCodec;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The outbound projection that makes an entry Publish land a git-trackable file at its FilePlacement
 * path (charter S2 / ADR-0165) — plus the JSON codec that keys a Puck page's `.json` extension.
 *
 * Asserts: the mirror writes the body VERBATIM (the Puck Data itself, not a wrapper envelope) at the
 * placement path; it is a no-op when no disk is configured (degrade-not-fabricate); and the `json`
 * format resolves an entry's placement extension to `.json`.
 */
class PlacedDiskMirrorTest extends TestCase
{
    public function test_mirror_writes_body_verbatim_at_placement_path(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');

        $mirror = new PlacedDiskMirror($disk);
        $this->assertTrue($mirror->enabled());

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Json, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $this->assertSame('audiostud/page/library-lyrics.json', $path);

        $body = ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['text' => 'Lyrics']]], 'zones' => []];
        $this->assertTrue($mirror->mirror($path, $body, 'audiostud'));

        $disk->assertExists($path);
        // The file IS the Puck Data verbatim — decodes straight back to the body (no {namespace, body} wrapper).
        $this->assertEquals($body, json_decode((string) $disk->get($path), true));
    }

    public function test_mirror_is_a_noop_when_no_disk_configured(): void
    {
        $mirror = new PlacedDiskMirror(null);

        $this->assertFalse($mirror->enabled());
        $this->assertFalse($mirror->mirror('audiostud/page/x.json', ['a' => 1], 'audiostud'));
    }

    public function test_json_codec_round_trips_and_extension_is_json(): void
    {
        $codec = new JsonBodyCodec;

        $this->assertSame('json', $codec->extension());
        $this->assertSame(UxFormat::Json, $codec->format());

        $body = ['root' => [], 'content' => [], 'zones' => []];
        $this->assertEquals($body, $codec->encode($codec->decode($body)));
    }
}
