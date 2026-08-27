<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Lifecycle\EntryPromoter;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Fixtures\FakeEntitlementResolver;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * `EntryPromoter` on a SINGLE-DATABASE host — realm-and-floor-reconciliation ticket 02.
 *
 * ## Why this file exists next to `EntryLifecycleTest`
 *
 * `EntryLifecycleTest` covers promote, and it passes. It also **defines its own `central`
 * connection** in `getEnvironmentSetUp()` — a second sqlite database — so every promote assertion it
 * makes is about a host where `central` is genuinely a separate database. That is one of the two
 * shapes this estate runs, and it is the shape that works.
 *
 * The other shape is the majority. `BeamServiceProvider::registerCentralConnectionAlias()` copies
 * `database.default` into `database.connections.central` at any host that does not declare one — 14
 * of the 16 beam-installing Herd roots, and all four starters. There, `central` is not a second
 * database; it is **the same database under a second name**. `laravel-beam-ux` is vendored by 15
 * hosts and its own suite never once exercises that.
 *
 * So the harness was installing the very condition that hid the defect — the same shape as a
 * security trace taken with the gate open. This case removes it: no `central` block, and a
 * FILE-backed sqlite default so the aliased connection genuinely resolves to the same tables.
 * (`:memory:` would not reproduce it — Laravel gives each `:memory:` connection its own database,
 * which would quietly restore the two-database assumption this test exists to drop.)
 */
class EntryPromoterSingleDatabaseTest extends TestCase
{
    private static string $database = '';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // pid-keyed: concurrent sessions share `sys_get_temp_dir()`, and a fixed name there has
        // already cost this estate a 29-failure phantom (AGENTS.md).
        self::$database = sys_get_temp_dir().'/beam-ux-single-db-'.getmypid().'.sqlite';
        touch(self::$database);

        // A connection name testbench does not own. Setting `testing` here does not survive —
        // testbench re-applies its own sqlite-`:memory:` block after `getEnvironmentSetUp`, and the
        // alias then copies THAT, quietly restoring the two-database assumption this test drops.
        $app['config']->set('database.connections.singledb', [
            'driver' => 'sqlite',
            'database' => self::$database,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $app['config']->set('database.default', 'singledb');

        // `central` pointed at the SAME database as the default — the state
        // `BeamServiceProvider::registerCentralConnectionAlias()` produces at any host that declares
        // no `central` block (it copies the default's block verbatim, so host/database/search_path
        // are identical). Set explicitly rather than left to the alias: the alias reads
        // `database.default` during `packageRegistered()`, and testbench is still settling its own
        // database config at that point, so relying on it here would test the harness's timing
        // instead of the condition. What a real host gets is this — 14 of 16 beam-installing Herd
        // roots and all four starters.
        $app['config']->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => self::$database,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->app->singleton(EntitlementResolver::class, fn () => new FakeEntitlementResolver);
        $this->app->singleton(FakeEntitlementResolver::class, fn ($app) => $app->make(EntitlementResolver::class));

        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (self::$database !== '' && file_exists(self::$database)) {
            @unlink(self::$database);
        }
    }

    /**
     * The premise, asserted rather than assumed: `central` resolves to the same database as the
     * default. If this ever stops holding, the promote assertion below is testing something else,
     * and it should fail loudly here first rather than pass for the wrong reason.
     */
    public function test_central_resolves_to_the_default_database(): void
    {
        $this->assertSame(
            DB::connection('singledb')->getDatabaseName(),
            DB::connection('central')->getDatabaseName(),
            '`central` should resolve to the SAME database — that is what makes promote a self-write.'
        );
    }

    /**
     * The defect, now the contract. "Promote this tenant entry to central" used to resolve to
     * `updateOrCreate` on the same table, keyed on the entry's OWN namespace+slug — so it found the
     * entry itself and updated it in place: new particle, rewritten `residency_mode`, and a return
     * value that looked like a successful promotion. It succeeded, returned a `BeamUxEntry`, and
     * threw nothing.
     *
     * It now refuses, and the source is asserted untouched — including the absence of the orphaned
     * particle the old ordering would have written before it ever looked at the target.
     */
    public function test_promote_refuses_when_central_is_the_entrys_own_table(): void
    {
        $this->app->make(FakeEntitlementResolver::class)->keys = ['ux.site.author'];

        $entry = $this->makeEntry(['slug' => 'hero', 'namespace' => 'kit', 'realm' => 'site']);
        $particlesBefore = BeamParticle::on('singledb')->count();

        try {
            $this->app->make(EntryPromoter::class)->promote($entry->fresh(), actor: (object) []);
            $this->fail('promote should refuse when `central` resolves to the entry\'s own table.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('no central tier', $e->getMessage());
        }

        $source = BeamUxEntry::on('singledb')->find($entry->id);

        $this->assertNotNull($source, 'the source entry must survive a refused promote.');
        $this->assertSame(
            $entry->particle_id,
            $source->particle_id,
            'a refused promote must not repoint the source at a new particle.'
        );
        $this->assertSame(
            'tenant-pinned',
            $source->residency_mode,
            'a refused promote must not rewrite the source\'s residency_mode.'
        );
        $this->assertSame(
            $particlesBefore,
            BeamParticle::on('singledb')->count(),
            'a refused promote must not leave an orphaned particle — the guard runs before the write.'
        );
    }

    private function makeEntry(array $attributes = []): BeamUxEntry
    {
        $entry = BeamUxEntry::create(array_merge([
            'slug' => 'entry',
            'namespace' => 'kit',
            'type' => UxType::Page,
            'realm' => 'site',
            'residency_mode' => 'tenant-pinned',
        ], $attributes));

        $particle = new BeamParticle;
        $particle->setConnection($entry->getConnectionName() ?? 'singledb');
        $particle->payload = ['heading' => 'Hi'];
        $particle->save();

        $entry->particle_id = $particle->id;
        $entry->save();

        return $entry->fresh();
    }

    private function createTables(): void
    {
        $schema = Schema::connection('singledb');

        $schema->dropIfExists('beam_ux_entries');
        $schema->dropIfExists(Beam::table('particles'));

        $schema->create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
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
            $table->timestamps();
            $table->softDeletes();
        });

        DB::connection('singledb')->statement(
            'create unique index beam_ux_entries_namespace_slug_active_unique '
            .'on beam_ux_entries (namespace, slug) where deleted_at is null'
        );

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
