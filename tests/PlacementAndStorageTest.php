<?php

namespace Splicewire\Beam\Ux\Tests;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DatePartitionedPlacement;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beamux-entry-charter S2 — the `FilePlacement` policy (ADR-0165 two trees: namespace→disk) and
 * beam-ux's consumption of the free-beam-core {@see StorageDriver} seam via its resolvers' precedence.
 *
 * Asserts: default path derivation (`namespace/type/slug.ext`, extension from `format` — NOT hardcoded
 * tsx); date-partitioned derivation; placement precedence (per-entry ref → namespace map → default);
 * driver-resolver precedence (same order). The StorageDriver contract itself is proven in beam-core's
 * StorageDriverTest — here we prove beam-ux SELECTS it correctly per entry.
 */
class PlacementAndStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createEntriesTable();
    }

    public function test_default_placement_derives_namespace_type_slug_with_extension_from_format(): void
    {
        $placement = new DefaultPlacement;

        $tsx = new BeamUxEntry(['slug' => 'hero', 'type' => UxType::Component, 'format' => UxFormat::Tsx, 'namespace' => 'kit.hero']);
        $this->assertSame('kit/hero/component/hero.tsx', $placement->pathFor($tsx));

        // Same entry as mdx — the extension follows `format`, NOT a hardcoded tsx (ADR-0164).
        $mdx = new BeamUxEntry(['slug' => 'about', 'type' => UxType::Page, 'format' => UxFormat::Mdx, 'namespace' => 'site.pages']);
        $this->assertSame('site/pages/page/about.mdx', $placement->pathFor($mdx));

        // A null namespace drops the grouping segment.
        $bare = new BeamUxEntry(['slug' => 'root', 'type' => UxType::Layout, 'format' => UxFormat::Tsx]);
        $this->assertSame('layout/root.tsx', $placement->pathFor($bare));
    }

    public function test_date_partitioned_placement_files_by_date(): void
    {
        $placement = new DatePartitionedPlacement('articles');

        $entry = new BeamUxEntry(['slug' => 'my-post', 'type' => UxType::Page, 'format' => UxFormat::Mdx, 'namespace' => 'blog']);
        $entry->created_at = Carbon::parse('2026-08-15');

        $this->assertSame('articles/2026/08/my-post.mdx', $placement->pathFor($entry));
    }

    public function test_placement_precedence_entry_ref_then_namespace_map_then_default(): void
    {
        $resolver = (new PlacementResolver)
            ->register(PlacementResolver::DEFAULT, new DefaultPlacement)
            ->register('date-partitioned', new DatePartitionedPlacement)
            ->mapNamespaces(['blog' => 'date-partitioned']);

        // 3. default — no ref, namespace unmatched.
        $default = new BeamUxEntry(['slug' => 's', 'type' => UxType::Component, 'namespace' => 'kit.hero']);
        $this->assertInstanceOf(DefaultPlacement::class, $resolver->resolve($default));

        // 2. namespace map — `blog` maps to date-partitioned.
        $mapped = new BeamUxEntry(['slug' => 's', 'type' => UxType::Page, 'namespace' => 'blog']);
        $this->assertInstanceOf(DatePartitionedPlacement::class, $resolver->resolve($mapped));

        // 1. per-entry ref WINS over the namespace map (blog would map, but the ref overrides to default).
        $override = new BeamUxEntry(['slug' => 's', 'type' => UxType::Page, 'namespace' => 'blog', 'placement_ref' => PlacementResolver::DEFAULT]);
        $this->assertInstanceOf(DefaultPlacement::class, $resolver->resolve($override));
    }

    public function test_driver_resolver_precedence_entry_ref_then_namespace_map_then_default(): void
    {
        $default = new FakeDriver('default');
        $special = new FakeDriver('special');

        $resolver = (new StorageDriverResolver)
            ->register(StorageDriverResolver::DEFAULT, $default)
            ->register('special', $special)
            ->mapNamespaces(['site' => 'special']);

        // 3. default.
        $this->assertSame('default', $resolver->resolve(new BeamUxEntry(['slug' => 'a', 'type' => UxType::Component, 'namespace' => 'kit']))->label);

        // 2. namespace map — `site.pages` matches the `site` prefix.
        $this->assertSame('special', $resolver->resolve(new BeamUxEntry(['slug' => 'a', 'type' => UxType::Page, 'namespace' => 'site.pages']))->label);

        // 1. per-entry driver_ref wins.
        $this->assertSame('default', $resolver->resolve(new BeamUxEntry(['slug' => 'a', 'type' => UxType::Page, 'namespace' => 'site.pages', 'driver_ref' => StorageDriverResolver::DEFAULT]))->label);
    }

    private function createEntriesTable(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}

/** A stand-in {@see StorageDriver} whose only job is to be identified by label in precedence assertions. */
class FakeDriver implements StorageDriver
{
    public function __construct(public string $label) {}

    public function read(string $key): ?StorageItem
    {
        return null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        return new StorageItem($key, $body, $namespace);
    }

    public function list(?string $namespace = null): array
    {
        return [];
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return 0;
    }
}
