<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;

/**
 * particle-operation-surface ticket 08 — which authorization PLANE the two `ux.author` operations are
 * checked on.
 *
 * `ux.author` is an ENTITLEMENT key, and `abilityModel: false` is what routes it to the subject-free
 * entitlement plane (`entitlement:ux.author`) instead of the per-action policy cascade. Without the
 * flag the resolver is handed the loaded `BeamUxEntry` and asked a per-action question with an
 * entitlement token.
 *
 * Why this file exists even though beam-core already pins the FLAG's mechanism
 * (`HttpTransportAbilityTest::test_ability_model_false_routes_the_check_to_the_subject_free_entitlement_plane`):
 * that test proves the flag works on a synthetic operation. This one proves the two SHIPPED
 * declarations carry it, through the real attribute → registry → route → controller path — which is
 * the half that silently rots when someone regenerates a declaration from the stub.
 *
 * The contrast is what makes it an assertion rather than a tautology: the two Gate abilities are
 * defined to DISAGREE, so a request can only pass by having consulted the entitlement one. Note that
 * `beam-accounts` is deliberately absent from this testbench, so the bare `ux.author` alias it defines
 * in a real host (`fn ($user) => $user->can('entitlement:ux.author')`) cannot mask the difference here
 * the way it does in production — that masking is precisely the accident ticket 08 measured and removed.
 */
class EntryWorkflowOpAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }

    // ── The declaration ─────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{class-string}>
     */
    public static function opClasses(): array
    {
        return [
            'show' => [EntryWorkflowShowOp::class],
            'transition' => [EntryWorkflowTransitionOp::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('opClasses')]
    public function test_both_ux_author_ops_declare_the_entitlement_plane(string $class): void
    {
        $attribute = (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();

        $this->assertSame('ux.author', $attribute->ability);
        $this->assertFalse(
            $attribute->abilityModel,
            "[{$class}] declares an entitlement key; `abilityModel: false` is what keeps it on the "
            .'entitlement plane. `null` here silently re-asks it as a policy verb.',
        );
    }

    // ── The behaviour, end to end ───────────────────────────────────────────────────────────────────

    public function test_the_entitlement_gate_is_the_one_consulted_not_a_policy_verb(): void
    {
        // Deliberately opposed: only a consultation of the ENTITLEMENT ability can answer "allowed".
        Gate::define('entitlement:ux.author', fn (?User $user = null) => $user instanceof AuthoringUser);
        Gate::define('ux.author', fn (?User $user = null) => false);

        $entry = $this->mountShowOpOn(BeamUxEntry::create([
            'slug' => 'about', 'type' => UxType::Page, 'segment' => '/about',
        ]));

        $this->actingAs(new AuthoringUser);

        $this->postJson("/beam-ux-entry/{$entry->id}/op/workflow")->assertOk();
    }

    public function test_an_actor_without_the_entitlement_is_forbidden(): void
    {
        // The inverse, and the half that would have gone unnoticed: with the entitlement DENIED and the
        // policy verb ALLOWED, a check that had drifted back onto the policy plane would answer 200.
        Gate::define('entitlement:ux.author', fn (?User $user = null) => false);
        Gate::define('ux.author', fn (?User $user = null) => true);

        $entry = $this->mountShowOpOn(BeamUxEntry::create([
            'slug' => 'about', 'type' => UxType::Page, 'segment' => '/about',
        ]));

        $this->actingAs(new AuthoringUser);

        $this->postJson("/beam-ux-entry/{$entry->id}/op/workflow")->assertForbidden();
    }

    /**
     * Register the REAL declaration through the same attribute-discovery path a host boots, then mount
     * its route. Building the `ParticleOperation` by hand here would test this file's copy of the
     * declaration rather than the shipped one.
     */
    private function mountShowOpOn(BeamUxEntry $entry): BeamUxEntry
    {
        $this->app->make(AttributedParticleDiscovery::class)->registerClass(EntryWorkflowShowOp::class);

        Route::particleOp('beam-ux-entry', 'beam-ux-entry', 'workflow');

        return $entry;
    }
}

class AuthoringUser extends User
{
    protected $table = 'users';
}
