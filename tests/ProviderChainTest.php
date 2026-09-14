<?php

namespace Splicewire\Beam\Ux\Tests;

use Rushing\Popcorn\Concerns\TraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

/**
 * The provider's two chains — beam-ux's adoption of popcorn's trait-method chain, and the first place
 * in the estate where ONE class runs TWO of them.
 *
 * `packageRegistered()` and `packageBooted()` each carried a hand-written index of the provider's own
 * parts — eleven `$this->register*()` calls and six more mixing `register*`/`boot*` prefixes. Each
 * concern now lives in the trait that owns it (`Concerns\Wires*`), declaring `#[Chained(...)]`, and both
 * blocks are one `chainTraitMethods()` call. The provider drops 468 lines, 728 → 260.
 *
 * ⚠️ **Two traits contribute to BOTH chains** — `WiresSitemap` (a binding, then the handover to
 * beam-sitemap) and `WiresPublicSurface` (bindings, then the route macro). That is one concern in one
 * file, and it is the case Eloquent's `boot{TraitBasename}` naming convention structurally cannot
 * express: the name is the identity, so it affords one method per trait per chain. It is why the
 * attribute form was copied instead.
 *
 * ⚠️ **The order assertions are the whole safety of the conversion.** These seventeen links were
 * sequenced by hand and several are order-dependent (a binding before the macro that resolves it).
 * `pint`'s Laravel preset ships `ordered_traits`, which sorts a class's `use` statements alphabetically
 * — and it re-sorted this provider's `use` block on the first run after the conversion. A chain resting
 * on `use` position would be resequenced by a formatter on an unrelated commit with nothing failing.
 * The `order:` values carry it; these tests prove they still say what the deleted call blocks said.
 */
class ProviderChainTest extends TestCase
{
    /** `packageRegistered()`'s hand-written block, verbatim. Change only with the reason written down. */
    private const HISTORICAL_REGISTER_ORDER = [
        'registerCodecs',
        'registerCompile',
        'registerPublic',
        'registerInference',
        'registerPlacement',
        // Added by the frame-nav promotion (OTB M2), not part of the historical hand-written block:
        // it binds `Schemastud\Frame\Contracts\FrameNavContributor`, so `/frame/manifest` carries
        // `nav` + `routeContext` at any beam-ux host instead of only at the one host that hand-wrote
        // its own manifest controller. Sequenced at 55 — after the plan/projector bindings it
        // resolves and before the storage/access links, none of which it touches.
        'registerFrameNav',
        'registerStorage',
        'registerAccess',
        'registerContainment',
        'registerSitemap',
        'registerDisk',
        'registerEntitlements',
    ];

    /** `packageBooted()`'s hand-written block, verbatim — note it mixed `boot*` and `register*` prefixes. */
    private const HISTORICAL_BOOT_ORDER = [
        // Added by ADR-0214 §5 (beam-docs-satellite 30), not part of the historical hand-written block:
        // the package registers its own particle declarations FIRST, so every later boot link — the
        // route macros above all — sees a populated registry rather than one a host had to fill in.
        'bootParticleDeclarations',
        'bootSitemap',
        'registerEntryWorkflow',
        'bootCommands',
        // `bootRouteMacro` (the `Route::beamUxEntries()` registration) was here until ADR-0214 §6 was
        // executed by beam-docs-satellite ticket 40. `WiresEntryRoutes` is deleted, so the link is gone
        // — not renamed, not unhooked. The count assertion below moved 10 → 9 with it.
        'bootPublicRouteMacro',
        // Added 2026-09-05 with the FrameResourcesInvocable lift: the collector that attaches a
        // resource declaring `section:` to its nav seat was host code at exactly one host. Registering
        // it here means any host gets it. `order: 55` seats it between `bootPublicRouteMacro` (50) and
        // `registerThemeSchemas` (60) — it needs the particle declarations of link 5, nothing later.
        // The count assertion below moved 9 -> 10 with it.
        'bootFrameNavCollector',
        // ...and its pair at order 56: the default navigation built from those declared seats. Two
        // links rather than one because the collector is a CAPABILITY (harmless if nothing points at
        // it) while this one registers an actual navigation — a host superseding the second still
        // wants the first.
        'bootDeclaredSectionNavigations',
        // ...and at 57, beam-ux seating its OWN `ops`/`authoring` sections through the same public
        // seam any other package uses. Deliberately not privileged: if this link were special-cased
        // rather than a NavSection registration, the seam would be untested by its first consumer.
        'bootOwnNavSections',
        // ...and at 58, the `{realm}-dashboard` resource per registered realm (realm-dashboards 04):
        // registered HERE for the realms known by now (a host route file reads the projection while
        // routes load, before any Application::booted() callback), and swept once more on
        // `Application::booted()` so a realm a HOST provider registers at boot still gets its
        // dashboard. The count moved 12 -> 13 with it.
        'bootRealmDashboards',
        'registerThemeSchemas',
        // Added by registry-kernel ticket 38 — the three describes are boot links, and they sit in the
        // trait that owns each fill rather than in a provider block, because beam-ux's whole binding
        // surface is these traits (ticket 54's finding).
        'describeCodecs',
        'describePlacements',
        'describeStorageDrivers',
    ];

    private function chain(string $chain): array
    {
        return array_map(
            fn ($method) => $method->getName(),
            TraitMethods::in(BeamUxServiceProvider::class, $chain),
        );
    }

    public function test_the_register_chain_resolves_in_the_order_the_hand_written_block_used(): void
    {
        $this->assertSame(self::HISTORICAL_REGISTER_ORDER, $this->chain('register'));
    }

    public function test_the_boot_chain_resolves_in_the_order_the_hand_written_block_used(): void
    {
        $this->assertSame(self::HISTORICAL_BOOT_ORDER, $this->chain('boot'));
    }

    public function test_the_two_chains_do_not_leak_into_each_other(): void
    {
        // The chain a link joins is DECLARED, not derived from its method prefix — which matters here
        // precisely because the boot block contains methods named `register*`.
        $this->assertNotContains('registerEntryWorkflow', $this->chain('register'));
        $this->assertNotContains('registerThemeSchemas', $this->chain('register'));
        $this->assertNotContains('registerSitemap', $this->chain('boot'));
    }

    public function test_one_trait_contributes_to_both_chains(): void
    {
        $this->assertContains('registerSitemap', $this->chain('register'));
        $this->assertContains('bootSitemap', $this->chain('boot'));

        $this->assertContains('registerPublic', $this->chain('register'));
        $this->assertContains('bootPublicRouteMacro', $this->chain('boot'));
    }

    public function test_neither_chain_is_empty(): void
    {
        // Guards the dead-seam shape from the other side: a rename that unhooked every link would leave
        // the order assertions comparing two empty arrays, and a provider that boots clean binding
        // nothing. This estate has found that shape four times already.
        $this->assertCount(12, $this->chain('register'));
        $this->assertCount(13, $this->chain('boot'));
    }

    public function test_the_provider_declares_the_contract_so_a_detector_can_find_it(): void
    {
        $this->assertInstanceOf(ChainsTraitMethods::class, $this->app->getProvider(BeamUxServiceProvider::class));
    }
}
