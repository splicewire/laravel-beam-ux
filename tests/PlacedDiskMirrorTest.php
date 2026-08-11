<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The outbound projection that makes an entry Publish land a git-trackable file at its FilePlacement
 * path (charter S2 / ADR-0165). Every body rides its entry's own
 * `Splicewire\Beam\Ux\Codec\BodyCodec` `decode()` back to source text — the former Puck-page codegen branch (a structural Puck `Data` body
 * compiled to composed-JSX) is retired (ADR-0016), along with the write-safety "provenance is
 * immutable" guard it needed (there is no longer a second, generated write kind to guard against).
 */
class PlacedDiskMirrorTest extends TestCase
{
    private function mirror(?FilesystemAdapter $disk): PlacedDiskMirror
    {
        return new PlacedDiskMirror($disk);
    }

    public function test_a_page_decodes_to_source_at_its_placement_path(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);
        $this->assertTrue($mirror->enabled());

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $this->assertSame('audiostud/page/library-lyrics.tsx', $path);

        $body = $entry->codec()->encode('export default function Page() { return <div>Lyrics</div>; }');
        $this->assertTrue($mirror->mirror($entry, $path, $body));

        $disk->assertExists($path);
        $this->assertStringContainsString('Lyrics', (string) $disk->get($path));
    }

    public function test_an_mdx_page_decodes_to_mdx_source(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        $entry = new BeamUxEntry(['slug' => 'songs', 'type' => UxType::Page, 'format' => UxFormat::Mdx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $this->assertSame('audiostud/page/songs.mdx', $path);

        $body = $entry->codec()->encode("# Songs\n\nYour catalog.");
        $this->assertTrue($mirror->mirror($entry, $path, $body));

        $disk->assertExists($path);
        $this->assertStringContainsString('# Songs', (string) $disk->get($path));
    }

    public function test_a_re_saved_page_overwrites_the_prior_file(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);

        $mirror->mirror($entry, $path, $entry->codec()->encode('export default () => <div>First</div>;'));
        $mirror->mirror($entry, $path, $entry->codec()->encode('export default () => <div>Second</div>;'));

        $written = (string) $disk->get($path);
        $this->assertStringContainsString('Second', $written);
        $this->assertStringNotContainsString('First', $written);
    }

    public function test_mirror_is_a_noop_when_no_disk_configured(): void
    {
        $mirror = $this->mirror(null);
        $this->assertFalse($mirror->enabled());

        $entry = new BeamUxEntry(['slug' => 'x', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $this->assertFalse($mirror->mirror($entry, 'audiostud/page/x.tsx', $entry->codec()->encode('x')));
    }
}
