<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Auth\User;
use Rushing\DataNav\NavContext;
use Rushing\DataNav\NavRegistry;
use Splicewire\Beam\Nav\NavSeatLock;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Ux\Frame\DeclaredSectionNavigation;
use Splicewire\Beam\Ux\Frame\NavSectionProjector;

/**
 * The PRODUCER half of the soft lock (Frame OS ticket 11 / ADR-0014 §A4).
 *
 * ⚠️ **Measured before this existed:** `Rushing\DataNav\NavLocked` was built, `#[TypeScript]`-marked,
 * unit-tested and WIRE-VISIBLE while every other scrap of gate meta stays `#[Hidden]` — and a sweep
 * of every package `src` and every Herd host `app` found ZERO callers of `NavGateVerdict::lock` and
 * exactly one `->locked(` (`NavGate::apply()`'s own line, itself unreached). Nothing in the family
 * could put a lock on the wire, so no manifest ever carried one and no component ever read one.
 *
 * ## The subtlety that decides WHERE the producer can live
 *
 * `NavRegistry::build()` gates through `NavGate::allows()` — a bare `bool` that counts a lock as
 * ALLOWED — not through the three-way `NavGate::apply()`. So a `NavVerdictStage` returning a lock is
 * evaluated and then DISCARDED: nothing stamps the node, and the field stays null. A gate stage
 * therefore cannot be the producer, and the alternative — switching `build()` to `apply()` — would
 * move the projection into `rushing/laravel-data-nav`, whose own docblock says beam owns it.
 *
 * So the lock is produced at PROJECTION, before the node is ever gated, and the assertions below are
 * on the emitted ARRAY rather than on property reads, because the property surviving proves nothing
 * about the wire: `locked` earns its keep only by being one of the few nav fields that is NOT
 * stripped by `build()`'s `toArray()`/`from()` round-trip.
 */
class SoftLockedNavSeatTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Schemastud\Frame\FrameServiceProvider::class];
    }

    /**
     * Declare one seat for the `tenant` realm and register beam-ux's declared navigation for it, so
     * a build goes through the real projector rather than a fixture tree.
     */
    private function seat(
        ?array $entitlement,
        ?NavSeatLock $lock,
        ?string $permission = null,
        string $key = 'studio',
    ): void {
        $this->app->make(NavSectionRegistry::class)->register(
            new NavSection(
                key: $key,
                realm: 'tenant',
                label: ucfirst($key),
                icon: 'Clapperboard',
                href: '/'.$key,
                order: 10,
                entitlement: $entitlement,
                permission: $permission,
                lock: $lock,
            ),
            by: 'splicewire/laravel-beam-'.$key,
        );

        $this->app->make(NavRegistry::class)->register(
            'tenant',
            new DeclaredSectionNavigation($this->app->make(NavSectionProjector::class), 'tenant'),
            by: 'splicewire/laravel-beam-ux',
        );
    }

    /** Define an `entitlement:{key}` Gate ability, exactly as `BeamServiceProvider` does when a resolver is bound. */
    private function defineEntitlement(string $key, callable $verdict): void
    {
        $this->app->make(Gate::class)->define('entitlement:'.$key, $verdict);
    }

    /**
     * The built navigation's emitted array, keyed by section — the WIRE, after the full
     * gate → expand → active-stamp → `toArray()`/`from()` round-trip.
     *
     * @return array<string, array<string, mixed>>
     */
    private function wire(?User $user = null): array
    {
        $tree = $this->app->make(NavRegistry::class)
            ->build('tenant', new NavContext(user: $user, attributes: ['realm' => 'tenant']))
            ->toArray();

        $rows = [];

        foreach ($tree['items'] as $item) {
            $rows[$item['routeName']] = $item;
        }

        return $rows;
    }

    /**
     * THE test. An unentitled principal keeps the seat, and the emitted row carries the reason and the
     * opaque upsell token — the "monetized-but-unreached" state a hard gate cannot express.
     */
    public function test_a_soft_gated_seat_an_unentitled_principal_cannot_reach_reaches_the_wire_locked(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => false);
        $this->seat(['composition.music'], new NavSeatLock('Available on the Songwriter plan', 'go-songwriter'));

        $row = $this->wire()['studio.section'] ?? null;

        $this->assertNotNull($row, 'the seat survived the gate rather than being omitted');
        $this->assertSame(
            ['reason' => 'Available on the Songwriter plan', 'upsell' => 'go-songwriter'],
            $row['locked'],
        );
    }

    /**
     * The lock is on the wire and the gate vocabulary is NOT — the pair is the whole point.
     *
     * `NavNode::$meta` is `#[Hidden]` and `build()`'s round-trip strips it, which is what keeps host
     * gating tokens off the client (ADR-0119). `locked` is the deliberate exception. Asserting the
     * emitted KEY SET rather than reading properties is what makes this test able to fail for a silent
     * serialization rename.
     */
    public function test_the_lock_is_wire_visible_while_the_gate_meta_that_produced_it_is_not(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => false);
        $this->seat(['composition.music'], new NavSeatLock('Available on the Songwriter plan', 'go-songwriter'), permission: 'studio.view');

        $row = $this->wire()['studio.section'];

        $this->assertArrayHasKey('locked', $row);
        $this->assertArrayNotHasKey('meta', $row, 'the #[Hidden] gate bag never reaches the client');
        $this->assertStringNotContainsString('composition.music', json_encode($row));
        $this->assertStringNotContainsString('studio.view', json_encode($row));
    }

    /**
     * The PRINCIPAL is actually threaded. The ability below answers differently for a guest and for a
     * user, so a projector that ignored the `NavContext` — as `DeclaredSectionNavigation` did, taking
     * the context only to satisfy the registry's entry type — would answer identically for both and
     * this would fail.
     */
    public function test_the_verdict_follows_the_principal_in_the_nav_context(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => $user !== null);
        $this->seat(['composition.music'], new NavSeatLock('Available on the Songwriter plan', 'go-songwriter'));

        $this->assertNotNull($this->wire()['studio.section']['locked'], 'a guest holds nothing — locked');
        $this->assertNull($this->wire(new User)['studio.section']['locked'], 'the entitled user sees it unlocked');
    }

    /** An entitled principal gets the ordinary row: present, and NOT locked. */
    public function test_an_entitled_principal_gets_an_unlocked_row(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => true);
        $this->seat(['composition.music'], new NavSeatLock('Available on the Songwriter plan', 'go-songwriter'));

        $this->assertNull($this->wire()['studio.section']['locked']);
    }

    /**
     * Any-of, matching the hard entitlement stage: ONE held key unlocks the seat, even when the others
     * are denied. A projector that required every key would lock a seat its principal can reach.
     */
    public function test_holding_any_one_declared_key_unlocks_the_seat(): void
    {
        $this->defineEntitlement('composition.content', fn ($user = null) => false);
        $this->defineEntitlement('composition.music', fn ($user = null) => true);
        $this->seat(['composition.content', 'composition.music'], new NavSeatLock('Upgrade', 'go'));

        $this->assertNull($this->wire()['studio.section']['locked']);
    }

    /**
     * A seat that declares no lock is HARD-gated, exactly as before — this addition is inert for every
     * seat already declared. The unentitled principal does not see it at all, and there is nothing
     * on the wire to discover it from.
     */
    public function test_a_seat_with_no_lock_is_still_hard_gated_and_omitted(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => false);
        $this->seat(['composition.music'], null);

        // The hard gate is the host's registered stage, which a bare beam host does not install — so
        // what this pins at THIS tier is the half a projector owns: the seat still carries its
        // entitlement gate vocabulary into the meta bag for that stage to read, and carries no lock.
        $seat = $this->app->make(NavSectionProjector::class)->project('tenant', new NavContext)[0];

        $this->assertNull($seat->locked, 'no lock was declared, so none is produced');
        $this->assertSame(['composition.music'], $seat->meta['entitlement']);
    }

    /**
     * The orthogonality rule. A lock is the ENTITLEMENT answer ("your plan does not include this"); a
     * denial is the PERMISSION answer ("not for you"). A soft seat therefore surrenders its
     * `entitlement` meta — otherwise a host's entitlement stage would omit the node the lock exists to
     * keep visible — and KEEPS its `permission` meta, so RBAC still omits.
     *
     * Getting this backwards shows an upsell to a user whose organisation already pays.
     */
    public function test_a_lock_softens_the_entitlement_axis_only_and_never_the_permission_axis(): void
    {
        $this->defineEntitlement('composition.music', fn ($user = null) => false);
        $this->seat(['composition.music'], new NavSeatLock('Upgrade', 'go'), permission: 'studio.view');

        $seat = $this->app->make(NavSectionProjector::class)->project('tenant', new NavContext)[0];

        $this->assertArrayNotHasKey('entitlement', $seat->meta, 'the softened axis is surrendered to the lock');
        $this->assertSame('studio.view', $seat->meta['permission'], 'RBAC is untouched and still omits');
        $this->assertNotNull($seat->locked);
    }

    /**
     * "Not configured" is not "denied". beam registers an `entitlement:{key}` ability only when a host
     * has bound an `EntitlementResolver`; `Gate::allows()` on an undefined ability returns false, which
     * is indistinguishable from a real denial. Reading that false as unentitled would turn a working
     * section into a permanent upsell at every bare beam host that never opted into the entitlement
     * plane at all — so the projector asks `has()` first and stays inert.
     */
    public function test_an_entitlement_key_the_host_never_defined_leaves_the_seat_unlocked(): void
    {
        // Deliberately define NOTHING.
        $this->seat(['composition.music'], new NavSeatLock('Upgrade', 'go'));

        $this->assertFalse($this->app->make(Gate::class)->has('entitlement:composition.music'));
        $this->assertNull($this->wire()['studio.section']['locked']);
    }

    /**
     * `entitlement: []` is a gate that was DECLARED and admits nobody — distinct from `null`, which is
     * ungated. `NavSection` argues that distinction at length; with a lock, it means always-locked.
     */
    public function test_a_declared_but_empty_entitlement_list_locks_unconditionally(): void
    {
        $this->seat([], new NavSeatLock('Coming soon'));

        $row = $this->wire()['studio.section'];

        $this->assertSame(['reason' => 'Coming soon', 'upsell' => null], $row['locked']);
    }

    /**
     * A lock on a seat that gated NOTHING has nothing to soften. Locking there would let a stray
     * `lock:` hide an always-visible section behind an upsell for everyone.
     */
    public function test_a_lock_on_an_ungated_seat_is_meaningless_and_locks_nothing(): void
    {
        $this->seat(null, new NavSeatLock('Upgrade', 'go'));

        $this->assertNull($this->wire()['studio.section']['locked']);
    }
}
