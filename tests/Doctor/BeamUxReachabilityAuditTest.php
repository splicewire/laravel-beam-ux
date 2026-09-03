<?php

namespace Splicewire\Beam\Ux\Tests\Doctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Ux\Doctor\BeamUxReachabilityAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\TestCase;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beam-docs-satellite 69's residual, made an instrument: **a composed URL is not evidence of
 * reachability.** At `splicewire/www` `UrlResolver` reported corrected URLs for all fifteen docs
 * guides while `EntryPathResolver::routable()` could not see six rows at all, because their `realms`
 * column was NULL — written past `BeamUxEntry::booted()`'s defaulting hook, and `whereJsonContains`
 * cannot match NULL. Fifteen 200s composed, a 404 served, and nothing in the estate compared the two.
 *
 * The four cases below are the four readings that distinguish this audit from one that succeeds by
 * not running: a healthy round trip, the exact `realms = NULL` defect, a round trip that lands on a
 * DIFFERENT entry, and a row whose containment chain cannot be walked at all — which is counted and
 * named rather than silently skipped.
 */
class BeamUxReachabilityAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_the_audit_is_registered_on_the_doctor_manifest_as_advisory(): void
    {
        // Testbench does not auto-discover: prove the manifest is the SHARED one, then that we are on it.
        $this->assertSame(app(BeamDoctorManifest::class), app(BeamDoctorManifest::class));

        $ours = array_values(array_filter(
            app(BeamDoctorManifest::class)->registrations(),
            fn (DoctorRegistration $r) => $r->audit === BeamUxReachabilityAudit::class,
        ));

        $this->assertCount(1, $ours, 'BeamUxReachabilityAudit is not registered on BeamDoctorManifest');
        $this->assertFalse($ours[0]->gate, 'whether a host publishes entries is a host fact — advisory, never a gate');
    }

    public function test_a_healthy_round_trip_passes_and_names_its_denominator(): void
    {
        $root = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create([
            'slug' => 'docs', 'type' => UxType::Page, 'namespace' => 'beam.docs',
            'segment' => '/beam/docs', 'parent_id' => $root->getKey(),
        ]);
        BeamUxEntry::create([
            'slug' => 'setup', 'type' => UxType::Page, 'namespace' => 'beam.docs.laravel',
            'segment' => 'setup', 'parent_id' => $docs->getKey(),
        ]);

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('3 published `page`', $findings[0]->detail);
    }

    public function test_a_null_realms_row_is_unreachable_even_though_its_url_composes(): void
    {
        $root = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create([
            'slug' => 'docs', 'type' => UxType::Page, 'namespace' => 'beam.docs',
            'segment' => '/beam/docs', 'parent_id' => $root->getKey(),
        ]);

        // Exactly the www defect: written PAST the model, so `booted()`'s creating hook never ran.
        DB::table('beam_ux_entries')->where('id', $docs->getKey())->update(['realms' => null]);

        // The composer is happy — which is the whole point of the ticket this audit encodes.
        $this->assertSame('/beam/docs', app(\Splicewire\Beam\Ux\Containment\UrlResolver::class)
            ->resolve($docs->fresh()));

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('/beam/docs', $findings[0]->detail);
        $this->assertStringContainsString('beam.docs/docs', $findings[0]->detail);
        $this->assertStringContainsString('realms', $findings[0]->detail);
    }

    public function test_a_url_that_resolves_to_a_different_entry_is_reported_as_a_mismatch(): void
    {
        $root = BeamUxEntry::rootFor();
        // Top-level, so `EntryPathResolver::absolute()`'s documented tie-break (null parent first,
        // then id) picks this one DETERMINISTICALLY — two non-null parents would tie on a uuid.
        $first = BeamUxEntry::create([
            'slug' => 'guides', 'type' => UxType::Page, 'namespace' => 'a',
            'segment' => '/guides',
        ]);
        // Same root-absolute segment under a different parent: ADR-0209 §10's uniqueness is
        // (parent_id, segment), so this is legal to author and one of the two can never be served.
        $shadow = BeamUxEntry::create([
            'slug' => 'guides', 'type' => UxType::Page, 'namespace' => 'b',
            'segment' => '/guides', 'parent_id' => $root->getKey(),
        ]);

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('resolves to a DIFFERENT entry', $findings[0]->detail);
        $this->assertStringContainsString('b/guides', $findings[0]->detail);
        unset($shadow);
    }

    public function test_a_row_whose_chain_cannot_be_walked_is_counted_not_skipped(): void
    {
        $root = BeamUxEntry::rootFor();
        $orphan = BeamUxEntry::create([
            'slug' => 'stray', 'type' => UxType::Page, 'namespace' => 'x',
            'segment' => 'stray', 'parent_id' => $root->getKey(),
        ]);

        // A dangling parent: the row claims containment the tree cannot supply. `UrlResolver` walks
        // `parent` and gets null, so it composes `/stray` as though the row were top-level — a
        // confident answer over a chain that does not exist.
        DB::table('beam_ux_entries')->where('id', $orphan->getKey())
            ->update(['parent_id' => '00000000-0000-4000-8000-000000000000']);

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertCount(2, $findings, 'the did-not-look counter is its own finding');
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('1 entry', $findings[1]->detail);
        $this->assertStringContainsString('x/stray', $findings[1]->detail);
        $this->assertStringContainsString('could not be evaluated', $findings[1]->detail);
    }

    public function test_a_page_declaring_no_segment_is_named_as_addressless_not_as_a_mismatch(): void
    {
        // Found on this audit's first live run at `splicewire/www`: eight published pages compose `/`
        // because they carry no segment, and the round trip lands on the realm root. It is unreachable
        // either way, but the repair is "give it a segment", not "resolve an ambiguity".
        $root = BeamUxEntry::rootFor();
        BeamUxEntry::create([
            'slug' => 'terms', 'type' => UxType::Page, 'namespace' => 'legal',
            'segment' => null, 'parent_id' => $root->getKey(),
        ]);

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('legal/terms', $findings[0]->detail);
        $this->assertStringContainsString('declares NO `segment`', $findings[0]->detail);
        $this->assertStringNotContainsString('DIFFERENT entry', $findings[0]->detail);
    }

    public function test_a_host_with_no_published_pages_is_inconclusive_and_names_its_candidate_count(): void
    {
        // No realm root, no entries at all — the population is empty, which is NOT a clean reading.
        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('0 published `page`', $findings[0]->detail);
    }

    public function test_an_unmigrated_host_is_inconclusive_not_a_pass(): void
    {
        Schema::drop('beam_ux_entries');

        $findings = app(BeamUxReachabilityAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('beam_ux_entries', $findings[0]->detail);
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->string('facade_ref')->nullable();
            $table->string('body_style')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
