<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;

/**
 * ux-walkthrough UX-08c: the two Developer-zone diagnostics are backed by the entries model, whose read every member of a
 * team passes. They declare a read ability of their own, so a member is refused while an entry's editors (owner, admin)
 * read them. The ability is "may update entries", the tier a team's editors already hold, so it needs no new token.
 */
class DiagnosticsReadAbilityTest extends TestCase
{
    public function test_both_diagnostics_declare_the_diagnostics_read_ability(): void
    {
        foreach ([MirrorStatusRowData::class, SitemapHealthRowData::class] as $data) {
            $declaration = (new ReflectionClass($data))->getAttributes(ParticleResource::class)[0]->newInstance();

            $this->assertSame(DiagnosticsAbility::NAME, $declaration->policy, "{$data} declares the diagnostics ability");
        }
    }

    public function test_the_ability_admits_an_entry_editor_and_refuses_a_reader(): void
    {
        Gate::define('beam-ux-entry.update', fn (DiagnosticsActor $user): bool => $user->role !== 'member');

        $this->assertTrue(Gate::forUser(new DiagnosticsActor(['role' => 'admin']))->allows(DiagnosticsAbility::NAME));
        $this->assertTrue(Gate::forUser(new DiagnosticsActor(['role' => 'owner']))->allows(DiagnosticsAbility::NAME));
        $this->assertFalse(Gate::forUser(new DiagnosticsActor(['role' => 'member']))->allows(DiagnosticsAbility::NAME));
        $this->assertFalse(Gate::forUser(null)->allows(DiagnosticsAbility::NAME), 'a guest never reads them');
    }

    /**
     * A host that places the diagnostics in its OPERATOR realm (the tower starter, IA-9) admits whoever that realm admits:
     * the operator entitlement. Measured: the tower starter's operator, holding `entitlement:os.operate` with no current
     * team, was 403'd on `beam-ux-mirror-status`, since the ability asked only the team-scoped entry update.
     */
    public function test_the_ability_admits_an_operator(): void
    {
        Gate::define('beam-ux-entry.update', fn (DiagnosticsActor $user): bool => false);
        Gate::define('entitlement:os.operate', fn (DiagnosticsActor $user): bool => $user->role === 'operator');

        $this->assertTrue(Gate::forUser(new DiagnosticsActor(['role' => 'operator']))->allows(DiagnosticsAbility::NAME));
        $this->assertFalse(Gate::forUser(new DiagnosticsActor(['role' => 'member']))->allows(DiagnosticsAbility::NAME));
    }
}

class DiagnosticsActor extends User
{
    protected $guarded = [];
}
