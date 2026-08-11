<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Entitlements\BeamUxRealmGrantable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ACC-01's beam-ux half: `BeamUxEntry` opts into `rushing/laravel-permission-cascade`'s `HasVisibility`
 * (the `visibilityAncestors()` override — nearest-first up `parent_id` to the containment root) and
 * beam-ux binds its OOTB `RealmGrantable` implementation ({@see BeamUxRealmGrantable}) so
 * beam-accounts' `DefaultEntitlementResolver` can resolve realm grants without depending on this
 * package. The grant-cascade RESOLUTION itself (Team membership → entitlement keys) is exercised in
 * beam-accounts' own suite (`DefaultEntitlementResolverTest`, `DemoLoginAsTest`) against a fixture
 * realm root — this file only proves beam-ux's two contributed seams.
 */
class EntitlementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_visibility_ancestors_walks_parent_id_nearest_first_up_to_the_realm_root(): void
    {
        $root = BeamUxEntry::rootFor('site');

        $section = BeamUxEntry::create([
            'slug' => 'section', 'type' => UxType::Page, 'segment' => '/section', 'parent_id' => $root->id,
        ]);

        $leaf = BeamUxEntry::create([
            'slug' => 'leaf', 'type' => UxType::Page, 'segment' => '/leaf', 'parent_id' => $section->id,
        ]);

        $ancestors = $leaf->visibilityAncestors();

        $this->assertCount(2, $ancestors);
        $this->assertTrue($ancestors[0]->is($section));
        $this->assertTrue($ancestors[1]->is($root));
    }

    public function test_visibility_ancestors_is_empty_for_a_top_level_entry(): void
    {
        $root = BeamUxEntry::rootFor('site');

        $this->assertSame([], $root->visibilityAncestors());
    }

    public function test_visibility_chain_is_self_then_ancestors(): void
    {
        $root = BeamUxEntry::rootFor('site');
        $leaf = BeamUxEntry::create([
            'slug' => 'leaf', 'type' => UxType::Page, 'segment' => '/leaf', 'parent_id' => $root->id,
        ]);

        $chain = $leaf->visibilityChain();

        $this->assertCount(2, $chain);
        $this->assertTrue($chain[0]->is($leaf));
        $this->assertTrue($chain[1]->is($root));
    }

    public function test_the_realm_grantable_binding_identifies_beam_ux_entry_as_the_grantable_morph(): void
    {
        $grantable = new BeamUxRealmGrantable;

        $this->assertSame((new BeamUxEntry)->getMorphClass(), $grantable->grantableMorphType());
    }

    public function test_the_realm_grantable_binding_resolves_a_realm_root_id_back_to_its_realm_name(): void
    {
        $root = BeamUxEntry::rootFor('operator');
        $grantable = new BeamUxRealmGrantable;

        $this->assertSame('operator', $grantable->realmForGrantableId($root->id));
        $this->assertNull($grantable->realmForGrantableId('not-a-real-id'));
    }

    public function test_the_realm_grantable_binding_lists_every_provisioned_realm_root(): void
    {
        BeamUxEntry::rootFor('site');
        BeamUxEntry::rootFor('operator');
        // A non-root entry (no `realms` namespace) must not appear.
        BeamUxEntry::create(['slug' => 'home', 'type' => UxType::Page, 'segment' => '/']);

        $grantable = new BeamUxRealmGrantable;

        $this->assertEqualsCanonicalizing(['site', 'operator'], $grantable->provisionedRealms());
    }

    public function test_the_realm_grantable_binding_root_for_finds_or_creates_the_same_root_beamuxentry_uses(): void
    {
        $grantable = new BeamUxRealmGrantable;

        $root = $grantable->rootFor('site');

        $this->assertTrue($root->is(BeamUxEntry::rootFor('site')));
    }

    public function test_the_service_provider_binds_its_own_realm_grantable_by_default(): void
    {
        $this->assertSame(
            BeamUxRealmGrantable::class,
            config('beam.accounts.entitlements.realm_grantable')
        );
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
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
            $table->integer('nav_order')->nullable();
            $table->timestamps();
            $table->unique(['namespace', 'slug']);
        });
    }
}
