<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\File;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\ProvenanceBackfill;
use Splicewire\Beam\Ux\Provenance\ProvenanceTemplate;
use Splicewire\Beam\Ux\Provenance\ProvenanceTemplates;
use Splicewire\Beam\Ux\Seed\SeedsEntries;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * DOCS-06b: rows seeded before the provenance migration carry no origin, so they never re-assert and live hosts never
 * converge. The backfill stamps an unstamped row only when its STORED body is exactly a known source: a disk file (now or
 * a recorded prior version) or a package template (current or a shipped prior one) apart from its `{{ token }}` spans.
 * Measured on the live hosts: 19 of 29 rows match a current source, 28 of 29 once the prior versions found are added.
 */
class ProvenanceBackfillTest extends TestCase
{
    private BackfillMemoryDriver $driver;

    private string $source;

    private const PRIOR_MCP = "---\ntitle: MCP Server\n---\n\n# MCP Server\n\nConnect your client to {{ endpoint_url }}.\n\nSeeded by the package.\n";

    private const CURRENT_MCP = "---\ntitle: MCP Server\n---\n\n# MCP Server\n\n{{ mcp_servers }}\n";

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $this->driver = new BackfillMemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));

        $this->source = sys_get_temp_dir().'/beam-ux-backfill-'.uniqid();
        File::ensureDirectoryExists($this->source.'/guides/page');

        app(ProvenanceTemplates::class)->register(fn (): array => [
            new ProvenanceTemplate(Provenance::package('splicewire/laravel-beam-mcp'), null, 'docs-mcp', self::CURRENT_MCP, 'mcp current'),
            new ProvenanceTemplate(Provenance::package('splicewire/laravel-beam-mcp'), null, 'docs-mcp', self::PRIOR_MCP, 'mcp prior'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    public function test_a_row_seeded_from_a_prior_template_is_stamped_and_then_re_asserts_to_the_current_one(): void
    {
        $row = $this->legacyRow('docs-mcp', null, 'MCP Server', str_replace('{{ endpoint_url }}', 'https://host.test/mcp', self::PRIOR_MCP));
        $this->migrate();

        $plan = $this->backfill()->plan([]);
        $this->assertSame([['docs-mcp', Provenance::package('splicewire/laravel-beam-mcp'), 'mcp prior']], $this->verdicts($plan));
        // review-r1: the dry run shows each token's captured value, so a person can see it is machine-written.
        $this->assertSame(['endpoint_url' => 'https://host.test/mcp'], $plan[0]['tokens']);
        $this->assertSame(1, $this->backfill()->apply($plan));

        $row->refresh();
        $this->assertSame(Provenance::package('splicewire/laravel-beam-mcp'), $row->origin);
        $this->assertSame(Provenance::hash('MCP Server', $this->storedBody($row)), $row->asserted_hash);

        // Now pristine, so the next seed re-asserts it to the current stub: the dead URL is gone.
        $current = str_replace('{{ mcp_servers }}', 'No MCP server is mounted.', self::CURRENT_MCP);
        $this->seeder()->seed('docs-mcp', null, $current, Provenance::package('splicewire/laravel-beam-mcp'), 'MCP Server');
        $this->assertStringNotContainsString('https://host.test/mcp', $this->storedBody($row->fresh()));
        $this->assertStringContainsString('No MCP server is mounted.', $this->storedBody($row->fresh()));
    }

    /** Lead 08:33Z: exact apart from token spans, so an edited row can never be mistaken for pristine and overwritten. */
    public function test_a_row_one_character_off_a_template_outside_its_tokens_is_left_unstamped_and_never_overwritten(): void
    {
        $edited = str_replace(['{{ endpoint_url }}', 'Seeded by the package.'], ['https://host.test/mcp', 'Seeded by the package!'], self::PRIOR_MCP);
        $row = $this->legacyRow('docs-mcp', null, 'MCP Server', $edited);
        $this->migrate();

        $plan = $this->backfill()->plan([]);
        $this->assertSame([['docs-mcp', null, 'no match']], $this->verdicts($plan));
        $this->assertSame(0, $this->backfill()->apply($plan));
        $this->assertNull($row->fresh()->origin);

        $this->seeder()->seed('docs-mcp', null, str_replace('{{ mcp_servers }}', 'x', self::CURRENT_MCP), Provenance::package('splicewire/laravel-beam-mcp'), 'MCP Server');
        $this->assertSame($edited, $this->storedBody($row->fresh()), 'an unknown row is never re-asserted');
    }

    public function test_a_disk_row_matches_its_current_file_or_a_recorded_prior_version_and_nothing_else(): void
    {
        File::put($this->source.'/guides/page/setup.mdx', "---\ntitle: Setup\n---\n\n# Setup\n\nRun composer setup.\n");
        File::put($this->source.'/guides/page/config.mdx', "---\ntitle: Config\n---\n\n# Config\n\nNew words.\n");
        File::put($this->source.'/guides/page/frame.mdx', "---\ntitle: Frame\n---\n\n# Frame\n\nNew words.\n");

        $setup = $this->legacyRow('setup', 'guides', 'Setup', "---\ntitle: Setup\n---\n\n# Setup\n\nRun composer setup.\n");
        $config = $this->legacyRow('config', 'guides', 'Config', $priorConfig = "---\ntitle: Config\n---\n\n# Config\n\nOld words.\n");
        $frame = $this->legacyRow('frame', 'guides', 'Frame', "---\ntitle: Frame\n---\n\n# Frame\n\nOld words, then edited.\n");
        $this->migrate();

        $history = ['guides/page/config.mdx' => [hash('sha256', $this->storedBody($config))]];
        $plan = $this->backfill()->plan([['path' => $this->source]], $history);

        $this->assertSame([
            ['setup', Provenance::disk('guides/page/setup.mdx'), 'disk file'],
            ['config', Provenance::disk('guides/page/config.mdx'), 'disk history'],
            ['frame', null, 'no match'],
        ], $this->verdicts($plan));
        $this->assertSame(2, $this->backfill()->apply($plan));
        $this->assertNull($frame->fresh()->origin);
        $this->assertSame($priorConfig, $this->storedBody($config->fresh()), 'the backfill stamps; it never rewrites a body');
    }

    public function test_a_stamped_or_cms_row_is_never_touched_and_the_backfill_refuses_before_the_migration(): void
    {
        $legacy = $this->legacyRow('docs-mcp', null, 'MCP Server', str_replace('{{ endpoint_url }}', 'u', self::PRIOR_MCP));

        try {
            $this->backfill()->plan([]);
            $this->fail('the backfill must refuse before the provenance migration');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('provenance migration', $e->getMessage());
        }

        $this->migrate();
        $cms = BeamUxEntry::create(['slug' => 'authored', 'type' => 'page', 'format' => 'mdx', 'title' => 'Authored']);
        $this->writeBody($cms, "# Authored\n");
        $this->assertSame(Provenance::CMS, $cms->fresh()->origin);

        $this->assertSame(['docs-mcp'], array_column($this->backfill()->plan([]), 'slug'));
        $this->assertSame(1, $this->backfill()->apply($this->backfill()->plan([])));
        $this->assertSame([], $this->backfill()->plan([]), 'a stamped row is never planned again');
        $this->assertSame(Provenance::CMS, $cms->fresh()->origin);
        $this->assertNotNull($legacy->fresh()->origin);
    }

    private function backfill(): ProvenanceBackfill
    {
        return app(ProvenanceBackfill::class);
    }

    private function migrate(): void
    {
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
    }

    /** A row created BEFORE the provenance migration: no origin column yet, so it stays null after it. */
    private function legacyRow(string $slug, ?string $namespace, string $title, string $source): BeamUxEntry
    {
        $row = BeamUxEntry::create(['slug' => $slug, 'namespace' => $namespace, 'type' => 'page', 'format' => 'mdx', 'title' => $title]);
        $this->writeBody($row, $source);

        return $row->fresh();
    }

    /** @return list<array{0: string, 1: ?string, 2: string}> */
    private function verdicts(array $plan): array
    {
        return array_map(fn (array $row): array => [$row['slug'], $row['origin'], $row['via']], $plan);
    }

    private function seeder(): object
    {
        return new class
        {
            use SeedsEntries;

            public function seed(string $slug, ?string $namespace, string $source, string $origin, string $title): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, ['title' => $title], UxFormat::Mdx, $namespace, $origin);
            }
        };
    }

    private function writeBody(BeamUxEntry $entry, string $source): void
    {
        $written = $this->driver->write('', $entry->codec()->encode($source), $entry->namespace);
        BeamUxEntry::query()->whereKey($entry->getKey())->update(['particle_id' => $written->key]);
    }

    private function storedBody(BeamUxEntry $entry): ?string
    {
        $item = $this->driver->read((string) $entry->fresh()->particle_id);

        return $item === null ? null : $entry->codec()->decode($item->body);
    }
}

/** An in-memory particle store that round-trips bodies by key. */
class BackfillMemoryDriver implements StorageDriver
{
    /** @var array<string, StorageItem> */
    private array $items = [];

    public function read(string $key): ?StorageItem
    {
        return $this->items[$key] ?? null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'p'.(count($this->items) + 1);

        return $this->items[$key] = new StorageItem($key, $body, $namespace, time());
    }

    public function list(?string $namespace = null): array
    {
        return array_values($this->items);
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return 0;
    }
}
