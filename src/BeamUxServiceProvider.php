<?php

namespace Splicewire\Beam\Ux;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Concerns\WiresAccess;
use Splicewire\Beam\Ux\Concerns\WiresCodecs;
use Splicewire\Beam\Ux\Concerns\WiresCommands;
use Splicewire\Beam\Ux\Concerns\WiresCompile;
use Splicewire\Beam\Ux\Concerns\WiresContainment;
use Splicewire\Beam\Ux\Concerns\WiresDisk;
use Splicewire\Beam\Ux\Concerns\WiresEntitlements;
use Splicewire\Beam\Ux\Concerns\WiresEntryRoutes;
use Splicewire\Beam\Ux\Concerns\WiresEntryWorkflow;
use Splicewire\Beam\Ux\Concerns\WiresInference;
use Splicewire\Beam\Ux\Concerns\WiresParticleDeclarations;
use Splicewire\Beam\Ux\Concerns\WiresPlacement;
use Splicewire\Beam\Ux\Concerns\WiresPublicSurface;
use Splicewire\Beam\Ux\Concerns\WiresSitemap;
use Splicewire\Beam\Ux\Concerns\WiresStorage;
use Splicewire\Beam\Ux\Concerns\WiresThemeSchemas;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;
use Splicewire\Beam\Ux\Doctor\BeamUxAccessAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxMigrationsAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The BeamUx authoring engine's provider (charter S0). Beam tier (ADR-0092/ADR-0159): it homes
 * the {@see BeamUxEntry} model + `beam_ux_entries` table. The versioned
 * body it rides is a beam-core {@see BeamParticle}, written through beam-core's
 * shared {@see ParticleWriter} — this package forks neither.
 *
 * The `beam_ux_entries` migration ships as a PUBLISH-ONLY spatie/laravel-package-tools stub
 * (`runsMigrations` FALSE, the estate-wide convention beam-core set — commit e7ae9b7): beam-ux never
 * `loadMigrationsFrom`'s or runs it at runtime; `vendor:publish --tag=beam-ux-migrations` (or
 * `splicewire:beam:install`) re-stamps + sequences a timestamped copy into the HOST at install time,
 * which runs it. The table is ubiquitous (central + every tenant — "everything is shared by
 * default"), so it publishes to the SINGLE `database/migrations/shared/` destination, not a
 * duplicated flat+tenant pair, registered via `->hasMigrations([...])` in
 * {@see self::configurePackage()}. beam-tenancy's `registerSharedMigrationsPath()` runs that one
 * directory in both the central `migrate` pass and Stancl's tenant pass.
 */
class BeamUxServiceProvider extends PackageServiceProvider implements ChainsTraitMethodsContract
{
    use ChainsTraitMethods;
    use WiresAccess;
    use WiresCodecs;
    use WiresCommands;
    use WiresCompile;
    use WiresContainment;
    use WiresDisk;
    use WiresEntitlements;
    use WiresEntryRoutes;
    use WiresEntryWorkflow;
    use WiresInference;
    use WiresParticleDeclarations;
    use WiresPlacement;
    use WiresPublicSurface;
    use WiresSitemap;
    use WiresStorage;
    use WiresThemeSchemas;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-ux')
            // beam-ux's migrations ship PUBLISH-ONLY as spatie/laravel-package-tools stubs — the
            // estate-wide convention (mirrors beam-core). The table is UBIQUITOUS (central + every
            // tenant — "everything is shared by default"), so it publishes to the SINGLE `shared/…`
            // destination. The old per-realm sitemap-anchor migration retired with ticket 03, in favor
            // of the `realms` fallback stack directly on `beam_ux_entries`.
            ->hasMigrations([
                'shared/create_beam_ux_entries_table',
            ]);

        // The docs seed stubs (ticket 02 §4, ADR-0210). Optionally published — publishing is how a host
        // customises what a fresh install seeds; not publishing is how it gets the default. Following
        // beam-core's own `beam-stubs` tag and beam-client-runtime's `resource_path('js/lib/')` publish:
        // a stub is established precedent, and it is not a RENDERED page, which is the thing no beam
        // package ships.
        $this->publishes(
            [__DIR__.'/../stubs/docs' => resource_path('beam-ux/docs')],
            'beam-ux-docs',
        );
    }

    public function packageRegistered(): void
    {
        // Merge the entry-body route config (api_root / route_name) as `config('beam.ux.*')`, so the
        // beamUxEntries() macro reads a config-driven, env-overridable prefix instead of a hardcoded one.
        $this->mergeConfigFrom(__DIR__.'/../config/beam/ux.php', 'beam.ux');

        // Every container binding this package makes, contributed by the trait that OWNS the concern
        // rather than hand-listed here. Each link declares its own `order:`, so adding a concern is
        // `use`-ing a trait — and the sequence does not rest on where a `use` statement sits, which
        // `pint`'s `ordered_traits` fixer resorts alphabetically.
        $this->chainTraitMethods('register');
    }

    public function packageBooted(): void
    {
        // BeamUxEntry implements WorkflowManaged, so EloquentAwaitingStore writes
        // `beam_workflow_awaiting.subject_type = $subject->getMorphClass()`. Unaliased it was
        // storing the raw FQCN in that column, and the alias is also the permission-token prefix
        // (ADR-0118). The package that owns the model owns its alias — a host should only have to
        // declare aliases for its OWN models.
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: a beam-composing host has many
        // models on class-string morphs. Mirrors {@see \Splicewire\Beam\BeamServiceProvider}.
        Relation::morphMap(['beam_ux_entry' => BeamUxEntry::class]);

        // The boot half of the same concerns — route macros, commands, the workflow and schema
        // handovers. `WiresSitemap` and `WiresPublicSurface` each contribute to BOTH chains, one
        // concern in one file, which is the shape the `boot{TraitBasename}` convention cannot express.
        $this->chainTraitMethods('boot');

        // beam-ux is an "operator" of the estate-wide publish-only stub migrations convention — self
        // registers the doctor/operator check on ITS OWN migrations DOWN into beam-core's aggregation
        // manifest, guarded on the manifest being bound (a host predating it still boots beam-ux fine).
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxMigrationsAudit::class,
            );

            // The standing check on ADR-0212's two rights: rows carrying a token the bound gate does
            // not know (a silent lockout), and INERT declarations — a child granting wider access than
            // its inherited constraint, which conjunction discards. §3 chose to accept-and-report those
            // rather than reject them; this is the reporting half of that bargain.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxAccessAudit::class,
            );

            // ADR-0209 §7's reporting seam: entries whose compiled artifact is missing or stale. It is
            // load-bearing rather than housekeeping — with no client-compile fallback, an uncompiled
            // page is a hard failure at read time, and this is what names it before a reader does.
            // ADR-0210 §6's orphan check rides the same audit: a page whose contributor was uninstalled
            // still 200s by design, so nothing else would ever mention it.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxArtifactAudit::class,
            );
        }

        // Self-registers its own install step (config merge is automatic; migrations publish tag +
        // migrate flag) DOWN into beam-core's install manifest, guarded the same way.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-ux',
                publishTags: ['beam-ux-migrations'],
                migrates: true,
                note: 'Optional: set beam.ux.storage.mirror_disk to a git-tracked disk (see config/filesystems.php) '.
                    'for diffable, version-controlled page bodies on publish. Unset by default — no disk writes '.
                    'until you opt in.',
            );
        }

        // Self-registers its seeder DOWN into beam-core's seed manifest, so one `splicewire:beam:seed`
        // provisions the realm root (ADR-0209 §9 — the renderer never writes, so something has to), the
        // docs subtree (ADR-0210), and the content nav, alongside every other package's seeders after a
        // migrate:fresh. Order 20, after the accounts substrate; guarded on the manifest being bound.
        //
        // Registration is UNGATED and the three gates moved inside {@see BeamUxSeeder}, because
        // `register()` is idempotent per PACKAGE name — a second registration would silently replace the
        // first, so one package gets one step and composes within it.
        if ($this->app->bound(BeamSeedManifest::class)) {
            $this->app->make(BeamSeedManifest::class)->register(
                package: 'splicewire/laravel-beam-ux',
                seederClass: BeamUxSeeder::class,
                order: 20,
            );
        }

    }
}
