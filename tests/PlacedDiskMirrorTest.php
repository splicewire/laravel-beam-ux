<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Splicewire\Beam\Ux\Codegen\PuckPageCodegen;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The outbound projection that makes an entry Publish land a git-trackable file at its FilePlacement
 * path (charter S2 / ADR-0165). It DISPATCHES on the entry (ADR-0164, the "NEXT" slice that retired the
 * json passthrough): a `page` whose body is a Puck `Data` document is CODEGEN'd to composed-JSX `.tsx`
 * (generated output); any other body (an mdx page, a hand-authored component) rides its `codec()->decode`
 * back to source text. Write-safety: a codegen write refuses to clobber a file lacking the `@generated`
 * marker, so it can never stomp hand-authored source.
 */
class PlacedDiskMirrorTest extends TestCase
{
    private function mirror(?FilesystemAdapter $disk): PlacedDiskMirror
    {
        return new PlacedDiskMirror($disk, new PuckPageCodegen('@/puck/blocks'));
    }

    public function test_a_puck_page_codegens_composed_tsx_at_its_placement_path(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);
        $this->assertTrue($mirror->enabled());

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $this->assertSame('audiostud/page/library-lyrics.tsx', $path);

        $body = ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['id' => 'h', 'text' => 'Lyrics']]], 'zones' => []];
        $this->assertTrue($mirror->mirror($entry, $path, $body));

        $disk->assertExists($path);
        $written = (string) $disk->get($path);
        // A clean composed-JSX React file — the Puck Data compiled to source, NOT the raw JSON verbatim.
        $this->assertStringContainsString(PuckPageCodegen::MARKER, $written);
        $this->assertStringContainsString('<Heading text="Lyrics" />', $written);
        $this->assertStringNotContainsString('"root"', $written);
    }

    public function test_an_mdx_page_decodes_to_mdx_source_not_codegen(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        $entry = new BeamUxEntry(['slug' => 'songs', 'type' => UxType::Page, 'format' => UxFormat::Mdx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $this->assertSame('audiostud/page/songs.mdx', $path);

        // An mdx page body is NOT Puck Data (no `root`/`zones`) — it rides the mdx codec back to source.
        $body = $entry->codec()->encode("# Songs\n\nYour catalog.");
        $this->assertTrue($mirror->mirror($entry, $path, $body));

        $disk->assertExists($path);
        $written = (string) $disk->get($path);
        $this->assertStringContainsString('# Songs', $written);
        $this->assertStringNotContainsString(PuckPageCodegen::MARKER, $written);
    }

    public function test_codegen_refuses_to_clobber_a_hand_authored_file(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);

        // A pre-existing file with NO @generated marker = hand-authored. Codegen must not stomp it.
        $disk->put($path, "export default function Hand() { return <div>authored</div>; }\n");

        $body = ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['text' => 'X']]], 'zones' => []];

        $this->expectException(RuntimeException::class);
        try {
            $mirror->mirror($entry, $path, $body);
        } finally {
            // The authored file is untouched.
            $this->assertStringContainsString('authored', (string) $disk->get($path));
        }
    }

    public function test_decoded_write_refuses_to_clobber_a_generated_file(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        // A generated page file already sits at the path.
        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);
        $mirror->mirror($entry, $path, ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['text' => 'Real']]], 'zones' => []]);

        // The SAME page entry momentarily carries a non-Puck body (mis-routes to codec.decode). The
        // provenance invariant must refuse to overwrite the generated file with decoded source.
        $this->expectException(RuntimeException::class);
        try {
            $mirror->mirror($entry, $path, ['source' => 'export default () => null;']);
        } finally {
            $this->assertStringContainsString('text="Real"', (string) $disk->get($path));
        }
    }

    public function test_codegen_overwrites_its_own_prior_generated_file(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake('beam-ux-mirror');
        $mirror = $this->mirror($disk);

        $entry = new BeamUxEntry(['slug' => 'library-lyrics', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $path = (new DefaultPlacement)->pathFor($entry);

        $mirror->mirror($entry, $path, ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['text' => 'First']]], 'zones' => []]);
        $mirror->mirror($entry, $path, ['root' => [], 'content' => [['type' => 'Heading', 'props' => ['text' => 'Second']]], 'zones' => []]);

        $written = (string) $disk->get($path);
        $this->assertStringContainsString('text="Second"', $written);
        $this->assertStringNotContainsString('text="First"', $written);
    }

    public function test_mirror_is_a_noop_when_no_disk_configured(): void
    {
        $mirror = $this->mirror(null);
        $this->assertFalse($mirror->enabled());

        $entry = new BeamUxEntry(['slug' => 'x', 'type' => UxType::Page, 'format' => UxFormat::Tsx, 'namespace' => 'audiostud']);
        $this->assertFalse($mirror->mirror($entry, 'audiostud/page/x.tsx', ['root' => [], 'content' => [], 'zones' => []]));
    }
}
