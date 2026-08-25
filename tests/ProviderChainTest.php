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
        'registerStorage',
        'registerAccess',
        'registerContainment',
        'registerSitemap',
        'registerDisk',
        'registerEntitlements',
    ];

    /** `packageBooted()`'s hand-written block, verbatim — note it mixed `boot*` and `register*` prefixes. */
    private const HISTORICAL_BOOT_ORDER = [
        'bootSitemap',
        'registerEntryWorkflow',
        'bootCommands',
        'bootRouteMacro',
        'bootPublicRouteMacro',
        'registerThemeSchemas',
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
        $this->assertCount(11, $this->chain('register'));
        $this->assertCount(6, $this->chain('boot'));
    }

    public function test_the_provider_declares_the_contract_so_a_detector_can_find_it(): void
    {
        $this->assertInstanceOf(ChainsTraitMethods::class, $this->app->getProvider(BeamUxServiceProvider::class));
    }
}
