<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * The theme a READER is served is the theme's Publication Pin, never its working copy (launch ticket 00,
 * 2026-10-15 21:40Z; ADR-0225 decision 2, ADR-0232 decision 4). Once the theme editor saves drafts, a
 * resolver that read the working payload would show every theme draft to every reader.
 *
 * Pre-migration hosts (no `published_version` column) keep reading the working payload — that is
 * {@see ThemeResolverTest}'s schema, and the doctor's version-store audit names those hosts.
 */
class ThemeResolverPublicationPinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('namespace')->nullable()->index();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->string('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });
    }

    /** @param  array<string, mixed>  $body */
    private function themeEntry(array $body): BeamUxEntry
    {
        $particle = new BeamParticle;
        $particle->payload = $body;
        $particle->save();

        return BeamUxEntry::create([
            'namespace' => ThemeResolver::NAMESPACE,
            'slug' => ThemeResolver::SLUG,
            'type' => UxType::Component->value,
            'particle_id' => $particle->id,
        ]);
    }

    /** @param  array<string, mixed>  $body */
    private function overwriteWorkingCopy(BeamUxEntry $entry, array $body): void
    {
        $particle = BeamParticle::find($entry->particle_id);
        $particle->payload = $body;
        $particle->save();
    }

    public function test_a_reader_is_served_the_pinned_theme_while_a_newer_draft_exists(): void
    {
        $entry = $this->themeEntry(['canvas' => ['accent' => '#111111']]);
        app(EntryPublication::class)->publish($entry->fresh());
        $this->assertNotNull($entry->fresh()->getAttribute('published_version'));

        // The author saves a draft: the working copy moves, the pin does not.
        $this->overwriteWorkingCopy($entry, ['canvas' => ['accent' => '#222222']]);

        $this->assertSame('#111111', (new ThemeResolver)->resolve()['canvas']['accent']);
    }

    public function test_publishing_the_draft_is_what_moves_the_reader(): void
    {
        $entry = $this->themeEntry(['canvas' => ['accent' => '#111111']]);
        app(EntryPublication::class)->publish($entry->fresh());
        $this->overwriteWorkingCopy($entry, ['canvas' => ['accent' => '#333333']]);

        app(EntryPublication::class)->publish($entry->fresh());

        $this->assertSame('#333333', (new ThemeResolver)->resolve()['canvas']['accent']);
    }

    public function test_a_theme_with_history_but_no_pin_is_an_unpublished_draft_and_contributes_no_override(): void
    {
        $entry = $this->themeEntry(['canvas' => ['accent' => '#444444']]);
        // History exists (a draft was recorded) but nothing was ever published: no pin.
        app(\Rushing\Versioning\Contracts\VersionStore::class)->snapshot(BeamParticle::find($entry->particle_id), 'draft');
        $this->assertNull($entry->fresh()->getAttribute('published_version'));

        // Schema default, not the unpublished working copy.
        $this->assertSame('#14803f', (new ThemeResolver)->resolve()['canvas']['accent']);
    }

    public function test_a_legacy_theme_with_no_history_is_still_served_until_the_baseline_pins_it(): void
    {
        // Written before the pin existed: no history, no pin. Its working copy IS what readers have
        // always been served, so the resolver keeps serving it (the baseline migration pins it).
        $this->themeEntry(['canvas' => ['accent' => '#555555']]);

        $this->assertSame('#555555', (new ThemeResolver)->resolve()['canvas']['accent']);
    }

    public function test_the_starters_theme_seeder_shape_renders_on_a_fresh_install(): void
    {
        // Exactly what the starters' byte-identical ThemeSeeder does on a fresh database (after migrate,
        // so the baseline migration found no entries to pin): a particle written directly, no pin, no
        // history. It must render (launch, orch-lead 2026-10-16 17:14Z; tots EP-01 moves the seeder).
        $particle = BeamParticle::create(['payload' => ['canvas' => ['accent' => '#666666']]]);
        BeamUxEntry::updateOrCreate(
            ['namespace' => ThemeResolver::NAMESPACE, 'slug' => ThemeResolver::SLUG],
            ['type' => UxType::Theme, 'title' => 'Theme', 'particle_id' => $particle->id],
        );

        $this->assertSame('#666666', (new ThemeResolver)->resolve()['canvas']['accent']);
    }

    public function test_reseeding_a_theme_the_baseline_already_pinned_moves_only_the_working_copy(): void
    {
        // Documents the consequence for EP-01: once the theme is pinned (the baseline migration on an
        // existing host), the seeder's direct payload update is a working-copy change, so readers keep
        // the pinned body until it is published. The seeder must publish through EntryPublication.
        $entry = $this->themeEntry(['canvas' => ['accent' => '#777777']]);
        app(EntryPublication::class)->baseline($entry->fresh());
        $this->assertNotNull($entry->fresh()->getAttribute('published_version'));

        BeamParticle::where('id', $entry->particle_id)->update(['payload' => ['canvas' => ['accent' => '#888888']]]);

        $this->assertSame('#777777', (new ThemeResolver)->resolve()['canvas']['accent']);
    }
}
