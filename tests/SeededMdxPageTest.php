<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Seed\SeedsEntries;

/**
 * ADR-0233 d9 ("a package runs it over the pages it ships"), EP-42 S1 (ruling R1): on a host whose MDX pages hold blocks,
 * a package's shipped MDX page is seeded converted through the MDX Lens, so a pristine package page re-asserts as blocks
 * and the host's MDX pages audit passes without an exemption. The package keeps shipping one MDX file. A page the lens
 * can't convert (no Node on the seeding host, a `<Content>` with no fragments) is seeded as source, as before.
 */
class SeededMdxPageTest extends AuthoredComponentNativeTestCase
{
    private SeedLens $lens;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
        $this->lens = new SeedLens;
        $this->app->instance(MdxLens::class, $this->lens);
        $this->lens->register('Shipped words.', [$this->p('Shipped words.')]);
        $this->lens->register('Newer shipped words.', [$this->p('Newer shipped words.')]);
    }

    public function test_on_a_host_that_holds_blocks_a_shipped_mdx_page_is_seeded_as_blocks(): void
    {
        $this->holdBlocks();

        $page = $this->seedDocs("---\ntitle: Docs\n---\n\nShipped words.\n");

        $this->assertSame([$this->p('Shipped words.')], $this->published($page), 'blocks, born published');
        $this->assertSame('package:acme/docs', $page->origin);
    }

    public function test_a_pristine_source_page_re_asserts_as_blocks_once_the_host_holds_blocks_and_an_edited_one_is_kept(): void
    {
        $pristine = $this->seedDocs("---\ntitle: Docs\n---\n\nShipped words.\n");
        $edited = $this->seedDocs("---\ntitle: Docs\n---\n\nShipped words.\n", 'edited');
        $pub = app(EntryPublication::class);
        $pub->publishVersion($edited->fresh(), $pub->recordDraft($edited->fresh(), MdxBody::encode("---\ntitle: Docs\n---\n\nOur words.\n"))->headVersion);
        $this->assertFalse(array_is_list($this->published($pristine)), 'seeded as source while the host keeps MDX as source');
        $this->holdBlocks();

        $this->seedDocs("---\ntitle: Docs\n---\n\nNewer shipped words.\n");
        $this->seedDocs("---\ntitle: Docs\n---\n\nNewer shipped words.\n", 'edited');

        $this->assertSame([$this->p('Newer shipped words.')], $this->published($pristine->fresh()), 'pristine: re-asserted, as blocks');
        $this->assertStringContainsString('Our words.', MdxBody::decode($this->published($edited->fresh())), 'edited: kept as the site wrote it');
    }

    public function test_a_page_the_lens_cannot_convert_is_seeded_as_source(): void
    {
        $this->holdBlocks();
        $this->lens->fails = true;

        $page = $this->seedDocs("---\ntitle: Docs\n---\n\nShipped words.\n");

        $this->assertSame("---\ntitle: Docs\n---\n\nShipped words.\n", MdxBody::decode($this->published($page)));
    }

    public function test_a_host_that_keeps_mdx_as_source_seeds_source_as_before(): void
    {
        $page = $this->seedDocs("---\ntitle: Docs\n---\n\nShipped words.\n");

        $this->assertSame("---\ntitle: Docs\n---\n\nShipped words.\n", MdxBody::decode($this->published($page)));
    }

    private function seedDocs(string $source, string $slug = 'docs'): BeamUxEntry
    {
        $seeder = new class
        {
            use SeedsEntries;

            public function run(string $slug, string $source): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, ['title' => 'Docs', 'realm' => 'site', 'parent_id' => BeamUxEntry::rootFor()->id,
                    'segment' => $slug], UxFormat::Mdx, origin: 'package:acme/docs');
            }
        };

        return $seeder->run($slug, $source)->fresh();
    }

    private function published(BeamUxEntry $page): array
    {
        return app(\Rushing\Versioning\Contracts\VersionStore::class)->version($page->particle, $page->published_version)->snapshot['payload'];
    }

    /** The host turns `holds_blocks` on: the codecs are chosen afresh, as in the seeding process. */
    private function holdBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
    }

    private function p(string $text): array
    {
        return ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]], 'dynamic' => false];
    }
}

/** An in-memory MDX Lens: registered bodies read as given, and whatever it prints reads back exactly. */
final class SeedLens implements MdxLens
{
    /** @var array<string, array> */
    private array $reads = [];

    public bool $fails = false;

    public function register(string $body, array $doc): void
    {
        $this->reads[trim($body)] = $doc;
    }

    public function read(string $body, array $context = []): array
    {
        if ($this->fails) {
            throw new \RuntimeException('SeedLens: no Node on this host');
        }

        return ['doc' => $this->reads[trim($body)] ?? throw new \RuntimeException('SeedLens: unregistered '.json_encode($body)), 'meta' => null, 'metaData' => null];
    }

    public function print(array $doc): string
    {
        $printed = implode("\n\n", array_map(fn (array $node) => (string) ($node['children'][0]['value'] ?? ''), $doc))."\n";
        $this->reads[trim($printed)] = $doc;

        return $printed;
    }
}
