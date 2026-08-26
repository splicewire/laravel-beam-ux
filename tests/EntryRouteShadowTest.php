<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\BeamUxRouteShadowAudit;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * beam-docs-satellite ticket 38 — the inverse of ADR-0209 §2.
 *
 * The renderer's catch-all is mounted last so it shadows nothing; the unwatched consequence is that
 * everything registered before it wins, including a route the host never wrote. Measured on a fresh
 * `laravel-beam-starter`: `knuckleswtf/scribe`'s unpublished defaults mount an HTML docs UI at `/docs`,
 * the seeded docs entry sat behind it, and `/docs` 500'd on a missing blade view while every other
 * beam-ux check passed.
 *
 * The cases below are written against the REAL macro rather than a hand-mounted stand-in, because
 * "which route wins" is a property of the collection the macro builds, not of a string comparison.
 */
class EntryRouteShadowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('beam_ux_entries');
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
    }

    public function test_a_host_with_no_public_renderer_is_not_audited(): void
    {
        $this->seedDocsEntry();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('beamUxSite', $findings[0]->detail);
    }

    public function test_an_entry_url_the_renderer_serves_passes(): void
    {
        $this->seedDocsEntry();
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /**
     * The measured case, reproduced: a package route registered BEFORE the catch-all at the same URL the
     * entries table addresses. Nothing else in beam-ux would ever mention it — the row is correct.
     */
    public function test_a_route_registered_before_the_catch_all_shadows_the_entry(): void
    {
        $this->seedDocsEntry();

        Route::get('docs', fn () => 'scribe')->name('scribe');
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('/docs', $findings[0]->detail);
        $this->assertStringContainsString('scribe', $findings[0]->detail);
    }

    /**
     * The shape a string comparison against `route:list` cannot see: a parameterised route swallows the
     * entry URL without ever naming it. This is why the audit hands the real collection a real request.
     */
    public function test_a_parameterised_route_that_swallows_the_url_is_caught(): void
    {
        $this->seedDocsEntry();

        Route::get('{legacy}', fn () => 'spa')->name('legacy.spa');
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('legacy.spa', $findings[0]->detail);
    }

    /** A reserved prefix excludes the URL from the renderer's constraint, so the entry is unreachable. */
    public function test_an_entry_seeded_under_a_reserved_prefix_is_reported(): void
    {
        $root = BeamUxEntry::rootFor();
        $this->page('api-guide', ['segment' => 'api', 'parent_id' => $root->getKey()]);
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('reserved_prefixes', $findings[0]->detail);
    }

    /**
     * The rule `CompileEntriesCommand` and {@see \Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit} each
     * had to learn, taught to this reader before it shipped: a bodyless row is a nav pointer parked at a
     * URL a named route already serves, so being served by that route is correct, not shadowed. Measured
     * on `laravel-beam-starter`, where seed-nav's three pointers were the audit's first output.
     */
    public function test_a_bodyless_nav_pointer_at_a_routed_url_is_not_a_finding(): void
    {
        $root = BeamUxEntry::rootFor();
        $this->page('dashboard', ['segment' => '/dashboard', 'parent_id' => $root->getKey(), 'particle_id' => null]);

        Route::get('dashboard', fn () => 'dash')->name('dashboard');
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /** A structural node has no segment, so no URL resolves to it and it is not a finding. */
    public function test_the_segment_less_realm_root_is_not_reported(): void
    {
        BeamUxEntry::rootFor();
        $this->mountRenderer();

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    private function audit(): BeamUxRouteShadowAudit
    {
        return app(BeamUxRouteShadowAudit::class);
    }

    private function mountRenderer(): void
    {
        Route::beamUxSite('site/entry');
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function seedDocsEntry(): BeamUxEntry
    {
        $root = BeamUxEntry::rootFor();

        return $this->page('docs', ['segment' => 'docs', 'parent_id' => $root->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function page(string $slug, array $attributes = []): BeamUxEntry
    {
        return BeamUxEntry::create(array_merge([
            'slug' => $slug,
            'type' => UxType::Page,
            'format' => UxFormat::Mdx,
            // A row with no particle is a nav POINTER, which the audit skips by design — see the audit.
            // Every page here is an authored one, so it carries a body reference.
            'particle_id' => (string) Str::uuid(),
        ], $attributes));
    }
}
