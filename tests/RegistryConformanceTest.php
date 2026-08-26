<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Placement\FilePlacement;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * beam-ux's three registries on the Popcorn kernel (registry-kernel ticket 38): they conform, they are
 * DESCRIBED into the shared index at boot, and their own vocabulary still round-trips.
 *
 * The first test is the tripwire ticket 27 D3 asks every swept package for. Without
 * `PopcornServiceProvider` in the harness, `RegistryIndex` resolves fresh per `make()`, every
 * `describe()` lands on a throwaway, and every assertion below would pass over an empty index.
 */
class RegistryConformanceTest extends TestCase
{
    public function test_the_registry_index_is_a_shared_singleton(): void
    {
        $this->assertSame(app(RegistryIndex::class), app(RegistryIndex::class));
    }

    public function test_beam_ux_roots_are_in_the_index_after_boot(): void
    {
        $index = app(RegistryIndex::class);

        foreach (['beam.ux.codecs', 'beam.ux.placements', 'beam.ux.storage-drivers'] as $root) {
            $this->assertTrue($index->has($root), "`{$root}` was never described into the index.");
        }

        $this->assertSame(app(CodecRegistry::class), $index->resolve('beam.ux.codecs'));
        $this->assertSame(app(PlacementResolver::class), $index->resolve('beam.ux.placements'));
        $this->assertSame(app(StorageDriverResolver::class), $index->resolve('beam.ux.storage-drivers'));
    }

    public function test_the_three_registries_implement_the_contract(): void
    {
        $this->assertInstanceOf(Registry::class, app(CodecRegistry::class));
        $this->assertInstanceOf(Registry::class, app(PlacementResolver::class));
        $this->assertInstanceOf(Registry::class, app(StorageDriverResolver::class));
    }

    public function test_the_codec_registry_round_trips_through_its_own_vocabulary(): void
    {
        $codecs = app(CodecRegistry::class);

        // The self-keying one-argument door the provider's seed already uses.
        $codecs->register(new TsxBodyCodec);

        $this->assertTrue($codecs->has(UxFormat::Tsx));
        $this->assertInstanceOf(TsxBodyCodec::class, $codecs->for(UxFormat::Tsx));
        $this->assertInstanceOf(TsxBodyCodec::class, $codecs->for('tsx'));
        $this->assertContains('tsx', $codecs->formats());

        // Keys go relative in and absolute out.
        $this->assertContains('beam.ux.codecs.tsx', array_map('strval', $codecs->keys()));
    }

    public function test_the_placement_resolver_round_trips_and_keeps_its_precedence(): void
    {
        $placements = app(PlacementResolver::class);

        $placements->register('conformance', new DefaultPlacement);

        // The contract read.
        $this->assertInstanceOf(FilePlacement::class, $placements->resolve('conformance'));
        $this->assertInstanceOf(FilePlacement::class, $placements->tryResolve('conformance'));
        $this->assertNull($placements->tryResolve('nothing-registered-here'));
        $this->assertContains('conformance', $placements->strategies());
        $this->assertContains(PlacementResolver::DEFAULT, $placements->strategies());
    }

    public function test_the_storage_driver_resolver_keeps_its_seeded_names(): void
    {
        $drivers = app(StorageDriverResolver::class);

        $this->assertContains(StorageDriverResolver::DEFAULT, $drivers->drivers());
        $this->assertContains('particle', $drivers->drivers());
        $this->assertTrue($drivers->has(StorageDriverResolver::DEFAULT));
    }
}
