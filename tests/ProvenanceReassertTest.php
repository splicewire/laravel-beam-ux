<?php

namespace Splicewire\Beam\Ux\Tests;

use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Seed\SeedsEntries;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * DOCS-06 / ADR-0215 §2: a pristine package:/disk: row re-asserts from its origin when the source
 * changes; an edited row is kept (reported by docs.diverged, never overwritten); a disk origin
 * supersedes a pristine package stub (DOC-5). Placement is never re-asserted.
 */
class ProvenanceReassertTest extends TestCase
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

        $this->seeder = new class
        {
            use SeedsEntries;

            public function seed(string $slug, string $source, ?string $origin, ?string $title = null): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, $title === null ? [] : ['title' => $title], UxFormat::Mdx, 'docs', $origin);
            }
        };
    }

    public function test_a_pristine_package_row_re_asserts_when_the_source_changes(): void
    {
        $origin = Provenance::package('splicewire/laravel-beam-docs');
        $first = $this->seeder->seed('index', "# Docs\nv1", $origin, 'Docs');
        $this->assertSame($origin, $first->origin);
        $this->assertSame(Provenance::hash('Docs', "# Docs\nv1"), $first->asserted_hash);

        // Re-seed the SAME coordinate with a changed source: pristine, so it re-asserts.
        $again = $this->seeder->seed('index', "# Docs\nv2 — a better stub", $origin, 'Docs');

        $this->assertSame($first->getKey(), $again->getKey(), 'same row, not a second one');
        $this->assertSame("# Docs\nv2 — a better stub", $this->storedBody($again));
        $this->assertSame(Provenance::hash('Docs', "# Docs\nv2 — a better stub"), $again->asserted_hash);
        $this->assertSame(1, BeamUxEntry::query()->where('slug', 'index')->count());
    }

    public function test_an_edited_row_is_spared(): void
    {
        $origin = Provenance::package('splicewire/laravel-beam-docs');
        $row = $this->seeder->seed('api', "# API\nv1", $origin, 'API');

        // The host edits the body directly (stored body now differs from asserted_hash).
        $this->writeBody($row, "# API\nedited by the host");

        // A re-seed with a newer stub must NOT overwrite the edit.
        $this->seeder->seed('api', "# API\nv2 stub", $origin, 'API');

        $this->assertSame("# API\nedited by the host", $this->storedBody($row->fresh()));
    }

    public function test_a_cms_row_is_never_re_asserted(): void
    {
        // A row authored in place (origin defaults to cms via the model) is never touched by a seed.
        $row = BeamUxEntry::create(['slug' => 'page', 'namespace' => 'docs', 'type' => 'page', 'format' => 'mdx', 'title' => 'Page']);
        $this->writeBody($row, "# Page\nauthored here");
        $this->assertSame(Provenance::CMS, $row->fresh()->origin);

        $this->seeder->seed('page', "# Page\na stub tries to take it", Provenance::package('splicewire/laravel-beam-docs'), 'Page');

        $this->assertSame("# Page\nauthored here", $this->storedBody($row->fresh()));
        $this->assertSame(Provenance::CMS, $row->fresh()->origin);
    }

    public function test_a_disk_file_supersedes_a_pristine_package_stub(): void
    {
        $pkg = Provenance::package('splicewire/laravel-beam-docs');
        $row = $this->seeder->seed('guide', "# Guide\nfrom the package", $pkg, 'Guide');
        $this->assertSame($pkg, $row->origin);

        // A host file at the same coordinate outranks the package stub (DOC-5) and re-asserts it.
        $disk = Provenance::disk('beam/docs/guide.mdx');
        $this->seeder->seed('guide', "# Guide\nfrom the host file", $disk, 'Guide');

        $this->assertSame($disk, $row->fresh()->origin);
        $this->assertSame("# Guide\nfrom the host file", $this->storedBody($row->fresh()));
    }

    private function writeBody(BeamUxEntry $entry, string $source): void
    {
        $written = $this->driver->write('', $entry->codec()->encode($source), $entry->namespace);
        $entry->forceFill(['particle_id' => $written->key])->save();
    }

    private function storedBody(BeamUxEntry $entry): ?string
    {
        $item = $this->driver->read((string) $entry->particle_id);

        return $item === null ? null : $entry->codec()->decode($item->body);
    }
}

/** An in-memory particle store that round-trips bodies by key. */
class ReassertMemoryDriver implements StorageDriver
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
