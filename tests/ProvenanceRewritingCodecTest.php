<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Codec\BodyCodec;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\UxFormatCase;
use Splicewire\Beam\Ux\Format\BodyStyle;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Seed\SeedsEntries;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

require_once __DIR__.'/ProvenanceReassertTest.php';

/**
 * DOCS-06 (lead 03:01Z): a codec may re-encode a body on write (CssBodyCodec regenerates its header), so the stored
 * body is not byte-identical to the source. The asserted hash is taken over the body AS STORED (the codec's round
 * trip), so such a row reads pristine right after a seed, still re-asserts when its source really changes, and is
 * still kept and reported after a manual edit.
 */
class ProvenanceRewritingCodecTest extends TestCase
{
    private ReassertMemoryDriver $driver;

    private object $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        (require $ux.'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();

        $this->driver = new ReassertMemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));
        app(CodecRegistry::class)->register(new HeaderRewritingCodec);

        $this->seeder = new class
        {
            use SeedsEntries;

            public function seed(string $slug, string $source, string $origin): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, ['title' => 'Guide'], UxFormat::Mdx, 'docs', $origin);
            }
        };
    }

    private function stored(BeamUxEntry $entry): string
    {
        return $entry->codec()->decode($this->driver->read((string) $entry->particle_id)->body);
    }

    private function diverged(BeamUxEntry $entry): bool
    {
        return Provenance::hash($entry->title, $this->stored($entry)) !== $entry->asserted_hash;
    }

    public function test_a_row_through_a_rewriting_codec_reads_pristine_after_seed(): void
    {
        $row = $this->seeder->seed('guide', "# Guide\nv1", Provenance::package('splicewire/laravel-beam-docs'));

        $this->assertStringStartsWith(HeaderRewritingCodec::HEADER, $this->stored($row), 'the codec did rewrite');
        $this->assertFalse($this->diverged($row), 'freshly seeded, so not diverged');
    }

    public function test_it_re_asserts_when_its_source_really_changes_and_not_when_it_does_not(): void
    {
        $origin = Provenance::package('splicewire/laravel-beam-docs');
        $first = $this->seeder->seed('guide', "# Guide\nv1", $origin);
        $hash = $first->asserted_hash;

        $same = $this->seeder->seed('guide', "# Guide\nv1", $origin);
        $this->assertSame($hash, $same->asserted_hash, 'same source: nothing re-asserted');

        $changed = $this->seeder->seed('guide', "# Guide\nv2", $origin);
        $this->assertStringContainsString('v2', $this->stored($changed));
        $this->assertFalse($this->diverged($changed));
        $this->assertNotSame($hash, $changed->asserted_hash);
    }

    public function test_a_manual_edit_is_kept_and_reported(): void
    {
        $origin = Provenance::package('splicewire/laravel-beam-docs');
        $row = $this->seeder->seed('guide', "# Guide\nv1", $origin);
        $this->driver->write((string) $row->particle_id, $row->codec()->encode("# Guide\nedited by the host"), 'docs');

        $again = $this->seeder->seed('guide', "# Guide\nv2", $origin);

        $this->assertStringContainsString('edited by the host', $this->stored($again), 'kept, never overwritten');
        $this->assertTrue($this->diverged($again), 'reported by docs.diverged');
    }
}

/** An mdx codec that regenerates a header on every encode, as CssBodyCodec does for themes. */
class HeaderRewritingCodec implements BodyCodec
{
    public const HEADER = "{/* generated header */}\n";

    public function format(): UxFormatCase
    {
        return UxFormat::Mdx;
    }

    public function extension(): string
    {
        return 'mdx';
    }

    public function encode(string $raw, ?BodyStyle $style = null): array
    {
        return ['source' => self::HEADER.(str_starts_with($raw, self::HEADER) ? substr($raw, strlen(self::HEADER)) : $raw)];
    }

    public function decode(array $body): string
    {
        return (string) ($body['source'] ?? '');
    }
}
