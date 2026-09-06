<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Theme\ThemeResolutionFailure;
use Splicewire\Beam\Ux\Theme\ThemeResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * Ticket 02 (theme-entries-and-authoring): the {@see ThemeResolver} cascade — package default
 * (ticket 01's JSON Schema `default` values) → central `beam_ux_entries` theme row → tenant
 * `beam_ux_entries` theme row, deep-merged at the per-token level, later wins. Never throws.
 */
class ThemeResolverTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables('testing');
    }

    private function createTables(string $connection): void
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('beam_ux_entries')) {
            $schema->create('beam_ux_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('particle_id')->nullable()->index();
                $table->string('slug')->index();
                $table->string('type')->index();
                $table->string('format')->default('tsx')->index();
                $table->string('namespace')->nullable()->index();
                $table->string('residency_mode')->default('context-following')->index();
                $table->string('realm')->default('site')->index();
                $table->json('realms')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['namespace', 'slug']);
            });
        }

        if (! $schema->hasTable(Beam::table('particles'))) {
            $schema->create(Beam::table('particles'), function (Blueprint $table) {
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
        }
    }

    private function writeThemeEntry(string $connection, array $body): void
    {
        $this->createTables($connection);

        $particle = new BeamParticle;
        $particle->setConnection($connection);
        $particle->payload = $body;
        $particle->save();

        $entry = new BeamUxEntry;
        $entry->setConnection($connection);
        $entry->fill([
            'namespace' => ThemeResolver::NAMESPACE,
            'slug' => ThemeResolver::SLUG,
            'type' => UxType::Component,
            'particle_id' => $particle->id,
        ]);
        $entry->save();
    }

    private function resolver(): ThemeResolver
    {
        return new ThemeResolver;
    }

    public function test_it_returns_schema_defaults_when_no_entries_exist_anywhere(): void
    {
        $theme = $this->resolver()->resolve();

        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);
        $this->assertSame('#f4f4f5', $theme['shell']['surface']);
        $this->assertSame('#FFFFFF', $theme['site']['background']);
    }

    public function test_central_absent_never_throws_and_falls_back_to_defaults(): void
    {
        // No 'central' connection tables exist at all (never migrated) — the resolver degrades, not throws.
        $theme = $this->resolver()->resolve();

        $this->assertIsArray($theme);
        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);
    }

    public function test_tenant_absent_returns_central_resolved_values_unchanged(): void
    {
        $this->writeThemeEntry('central', ['canvas' => ['accent' => '#FF0000']]);

        $theme = $this->resolver()->resolve();

        $this->assertSame('#FF0000', $theme['canvas']['accent']);
        // Untouched fields still fall through to the package default.
        $this->assertSame('#3A63E0', $theme['canvas']['accentHover']);
    }

    public function test_missing_central_table_does_not_hide_the_tenant_theme(): void
    {
        $this->assertFalse(Schema::connection('central')->hasTable('beam_ux_entries'));
        $this->writeThemeEntry('testing', ['canvas' => ['accent' => '#123456']]);

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        $this->assertSame('#123456', $theme['canvas']['accent']);
        $this->assertNull($resolver->lastFailure());
    }

    public function test_missing_tenant_table_does_not_discard_the_central_theme(): void
    {
        $this->writeThemeEntry('central', ['canvas' => ['accent' => '#654321']]);
        Schema::connection('testing')->drop('beam_ux_entries');

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        $this->assertSame('#654321', $theme['canvas']['accent']);
        $this->assertNull($resolver->lastFailure());
    }

    public function test_tenant_deep_merges_over_central_at_the_per_token_level_and_wins(): void
    {
        $this->writeThemeEntry('central', ['canvas' => ['accent' => '#FF0000', 'accentHover' => '#AA0000']]);
        $this->writeThemeEntry('testing', ['canvas' => ['accent' => '#00FF00']]);

        $theme = $this->resolver()->resolve();

        // Tenant's own key wins...
        $this->assertSame('#00FF00', $theme['canvas']['accent']);
        // ...but a central-only key survives (tenant didn't touch it, no full-object clobber).
        $this->assertSame('#AA0000', $theme['canvas']['accentHover']);
        // And package defaults still fill everything neither tier touched.
        $this->assertSame('#22C7B8', $theme['canvas']['editAccent']);
    }

    public function test_it_never_throws_even_when_the_central_connection_is_configured_but_unmigrated(): void
    {
        // 'central' connection exists in config (getEnvironmentSetUp) but its tables were never created —
        // every other test in this file already exercises this exact state implicitly (only
        // writeThemeEntry() ever migrates 'central'); this test names the invariant explicitly.
        $theme = $this->resolver()->resolve();

        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);
    }

    /**
     * A central `beam_ux_entries` that EXISTS but is the wrong shape — a stale-snapshot migration, the
     * exact "no such column" a host reads after a package moved its stub on. Not absence.
     */
    private function breakCentralTable(): void
    {
        Schema::connection('central')->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
        });
    }

    /**
     * Ticket 07: a non-absence failure still degrades to defaults (never-throw is kept) AND is
     * reported exactly once — the instrument must distinguish "nothing there" from "didn't look".
     */
    public function test_a_non_absence_failure_returns_defaults_and_is_reported_once(): void
    {
        Exceptions::fake();
        $this->breakCentralTable();

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);

        Exceptions::assertReported(QueryException::class);
        Exceptions::assertReportedCount(1);

        $failure = $resolver->lastFailure();
        $this->assertInstanceOf(ThemeResolutionFailure::class, $failure);
        $this->assertSame('central:default', $failure->entry);
        $this->assertSame(QueryException::class, $failure->exception);
        $this->assertSame('central', $failure->connection);
    }

    public function test_the_unmigrated_case_returns_defaults_and_reports_nothing(): void
    {
        Exceptions::fake();

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);

        Exceptions::assertNothingReported();
        $this->assertNull($resolver->lastFailure());
    }

    public function test_a_realm_tier_failure_names_the_realm_entry(): void
    {
        Exceptions::fake();
        // Central is healthy and holds the default row; the TENANT table is the broken one.
        $this->writeThemeEntry('central', ['canvas' => ['accent' => '#FF0000']]);
        Schema::connection('testing')->drop('beam_ux_entries');
        Schema::connection('testing')->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
        });

        $resolver = $this->resolver();
        $theme = $resolver->resolve('beam');

        // Degrades to PACKAGE defaults, not to the central-resolved value — the whole cascade is one
        // unit and a broken tier voids it; the report is what stops that reading as "no theme".
        $this->assertSame('#4F7CFF', $theme['canvas']['accent']);
        Exceptions::assertReportedCount(1);
        $this->assertSame('tenant:default', $resolver->lastFailure()?->entry);
    }
}
