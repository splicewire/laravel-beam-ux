<?php

namespace Splicewire\Beam\Ux;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The BeamUx authoring engine's provider (charter S0). Paid `splicewire/*` tier (ADR-0092): it homes
 * the {@see BeamUxEntry} model + `beam_ux_entries` table. The versioned
 * body it rides is a beam-core {@see BeamParticle}, written through beam-core's
 * shared {@see ParticleWriter} — this package forks neither.
 */
class BeamUxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerCodecs();
    }

    public function boot(): void
    {
        $this->bootMigrations();
    }

    /**
     * The {@see CodecRegistry} — the format→codec dispatch seam (ADR-0164). Bound as a singleton
     * seeded with the TSX + MDX codecs. The dispatch is paid `splicewire/*`; the MDX codec's engine is
     * the free-tier `laravel-beam-mdx` arm, folded in (not deleted) via {@see MdxBodyCodec}. A host can
     * `register()` further codecs on the same singleton for formats beyond the tsx/mdx seed set.
     */
    protected function registerCodecs(): void
    {
        $this->app->singleton(CodecRegistry::class, function () {
            return (new CodecRegistry)
                ->register(new TsxBodyCodec)
                ->register(new MdxBodyCodec);
        });
    }

    /**
     * Home the `beam_ux_entries` table with a **ubiquitous, context-following** shape (charter §Q7):
     * the SAME migration dir runs in BOTH migration passes, so the table exists identically in central
     * and every tenant.
     *
     * The mechanism (recohere T11, mirroring `Splicewire\Tower\TowerServiceProvider::bootMigrations()`):
     *
     *  - CENTRAL — {@see loadMigrationsFrom()} registers the shared dir with the framework migrator, so
     *    a plain `migrate` runs it against the central connection.
     *  - TENANT — Stancl tenancy has no auto-discovery; `tenants:migrate` reads the ARRAY at
     *    `config('tenancy.migration_parameters.--path')` at runtime. We push the SAME shared dir onto
     *    that array (install-location-agnostic, idempotent), so tenant provisioning runs it too. Boot
     *    runs at app-bootstrap, well before the command reads config, so ordering holds. (The app test
     *    harness's `migrateTenantPathIntoPublic()` reads this same array, so the shared table lands on
     *    the test DB via the tenant pass as well.)
     *
     * Gated by `config('beam.ux.register_migrations', true)` — defaults on, matching the beam-family
     * opt-out shape.
     */
    protected function bootMigrations(): void
    {
        if (! config('beam.ux.register_migrations', true)) {
            return;
        }

        $sharedDir = realpath(__DIR__.'/../database/migrations/shared')
            ?: __DIR__.'/../database/migrations/shared';

        // Central estate — auto-discovered by `migrate`.
        $this->loadMigrationsFrom($sharedDir);

        // Tenant estate — pushed onto Stancl's `--path` array (no auto-discovery). Same dir, so the
        // table shape is identical central + tenant.
        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($sharedDir, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $sharedDir);
        }
    }
}
