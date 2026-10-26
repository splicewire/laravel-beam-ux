<?php

namespace Splicewire\Beam\Ux;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\PermissionCascade\Support\CascadePolicyRegistrar;
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
use Splicewire\Beam\Ux\Concerns\WiresEntryWorkflow;
use Splicewire\Beam\Ux\Concerns\WiresFrameNav;
use Splicewire\Beam\Ux\Concerns\WiresInference;
use Splicewire\Beam\Ux\Concerns\WiresParticleDeclarations;
use Splicewire\Beam\Ux\Concerns\WiresPlacement;
use Splicewire\Beam\Ux\Concerns\WiresPublicSurface;
use Splicewire\Beam\Ux\Concerns\WiresRealmDashboards;
use Splicewire\Beam\Ux\Concerns\WiresSitemap;
use Splicewire\Beam\Ux\Concerns\WiresStorage;
use Splicewire\Beam\Ux\Concerns\WiresThemeSchemas;
use Splicewire\Beam\Ux\Data\BeamUxEntryData;
use Splicewire\Beam\Ux\Data\MirrorStatusRowData;
use Splicewire\Beam\Ux\Data\SitemapHealthRowData;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;
use Splicewire\Beam\Ux\Diagnostics\DiagnosticsAbility;
use Splicewire\Beam\Ux\Doctor\BeamUxAccessAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxChromeAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxIntakeFormAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxManifestRegistryAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxMdxPagesAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxMigrationsAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxReachabilityAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxRouteShadowAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxThemeResolutionAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxVersionStoreAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Preview\PreviewGrantResolver;
use Splicewire\Beam\Ux\Preview\PreviewGrantService;
use Splicewire\Beam\Ux\Query\BeamUxEntryResourceQuery;
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
    use WiresEntryWorkflow;
    use WiresFrameNav;
    use WiresInference;
    use WiresParticleDeclarations;
    use WiresPlacement;
    use WiresPublicSurface;
    use WiresRealmDashboards;
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
                'shared/add_requirements_to_beam_ux_entries_table',
                // The publication pin (`published_version`). An ALTER beside the create because
                // editing a `create_*` stub reaches nothing already migrated — a host that installed
                // beam-ux before this pass gets the column from here, a fresh one from the create,
                // and each no-ops for the other.
                'shared/add_published_version_to_beam_ux_entries_table',
                'shared/add_page_settings_to_beam_ux_entries_table',
                // The Page Fields a parent declares for its child pages (ADR-0233 d4). Nullable, beside its siblings.
                'shared/add_child_fields_to_beam_ux_entries_table',
                'shared/add_record_page_to_beam_ux_entries_table',
                'shared/add_component_kind_to_beam_ux_entries_table',
                'shared/add_component_names_to_beam_ux_entries_table',
                'shared/create_beam_ux_releases_table',
                // A Release's provenance (`origin`: direct | built). An ALTER beside the create for the same reason
                // as its siblings; existing Releases are `built`.
                'shared/add_origin_to_beam_ux_releases_table',
                // A Release's Preview Links (ADR-0232 d8): on/off and the secret they are signed with.
                'shared/add_preview_links_to_beam_ux_releases_table',
                // A site's Redirects (ADR-0236 d1): a row of their own, beside the entries they lead to.
                'shared/create_beam_ux_redirects_table',
                'shared/create_beam_ux_release_recordings_table',
                'shared/create_beam_ux_preview_grants_table',
                // The BASELINE (ADR-0225 decision 2; launch ticket 00, 2026-10-16): pins every already-written
                // entry to its live body inside `migrate`, so the pin is never required-but-missing. A
                // separate file because hosts that already ran the ALTER (or got the column from the create)
                // will never run it again. Idempotent.
                'shared/baseline_beam_ux_entry_publication_pins',
                // Provenance columns (`origin`, `asserted_hash`) — DOCS-06 / ADR-0215. An ALTER beside
                // the create for the same reason as its siblings; nullable, so it changes no existing row.
                'shared/add_provenance_to_beam_ux_entries_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(Bindings\RecordPageScope::class);
        $this->app->scoped(Components\ComponentScope::class);
        $this->app->bind(PreviewGrantResolver::class, PreviewGrantService::class);
        // DOCS-06b: the templates the provenance backfill may match an unstamped row against; packages register theirs.
        $this->app->singleton(Provenance\ProvenanceTemplates::class);

        // EP-07 (N3447): the shipped auth-page islands, composed into every realm's Manifest Profile. Packages tag their
        // own ManifestNodeSource the same way.
        $this->app->tag([Manifest\AuthIslandNodeSource::class, Manifest\IntakeFormNodeSource::class], Manifest\ManifestProfileResolver::PACKAGE_SOURCES);
        $this->app->tag([Manifest\SiteNodeSource::class], Manifest\ManifestProfileResolver::MDX_SOURCES);

        // Merge `config('beam.ux.*')`. Both keys OUTLIVED the retired `Route::beamUxEntries()` macro
        // they were introduced for (ADR-0214 §6, as amended): `api_root` positions beam core's Scribe
        // OpenAPI extraction window, `route_name` names the public-entry artifact route
        // ({@see Concerns\WiresPublicSurface}). Neither is a mount prefix any more.
        $this->mergeConfigFrom(__DIR__.'/../config/beam/ux.php', 'beam.ux');

        // Every container binding this package makes, contributed by the trait that OWNS the concern
        // rather than hand-listed here. Each link declares its own `order:`, so adding a concern is
        // `use`-ing a trait — and the sequence does not rest on where a `use` statement sits, which
        // `pint`'s `ordered_traits` fixer resorts alphabetically.
        $this->chainTraitMethods('register');
    }

    public function packageBooted(): void
    {
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::skipWhen(
            JsonDoc\PreservesJsonDocStrings::matches(...),
        );

        // BeamUxEntry implements WorkflowManaged, so EloquentAwaitingStore writes
        // `beam_workflow_awaiting.subject_type = $subject->getMorphClass()`. Unaliased it was
        // storing the raw FQCN in that column, and the alias is also the permission-token prefix
        // (ADR-0118). The package that owns the model owns its alias — a host should only have to
        // declare aliases for its OWN models.
        //
        // ADDITIVE (`Relation::morphMap`), NEVER `enforceMorphMap`: a beam-composing host has many
        // models on class-string morphs. Mirrors {@see \Splicewire\Beam\BeamServiceProvider}.
        Relation::morphMap(['beam_ux_entry' => BeamUxEntry::class]);
        // A Redirect is the subject of its own activity rows (ADR-0236 Consequences), stored for good: by alias.
        Relation::morphMap(['beam_ux_redirect' => Models\BeamUxRedirect::class]);
        // ADR-0236 d3: a Record's URL change leaves a Redirect, caught on the signal every pipeline write emits.
        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
            ->listen(\Splicewire\Beam\Events\BeamParticlePersisted::class, [Redirect\RecordUrlChanges::class, 'handle']);

        // UX-08c: the read ability the Developer-zone diagnostics declare (who may update entries; Root passes).
        DiagnosticsAbility::define();

        // `BeamUxEntry` binds its authoring-API authorization HERE, in the package that owns the model
        // (api-surface-coherence 147; the shape 135 landed for beam's `Hook`). Until this line existed
        // the model carried NO policy, and the estate read that absence four ways at once:
        // `ResourceFiltersController` fell through, `ParticleController::show()`/`destroy()` denied
        // everyone but a host's Root bypass, `GateWriteGate` denied every REST write, and the Frame nav
        // hid the seat. One declaration, consumed by all four; the `beam-ux-entry.*` family is seeded to
        // a host's admin role by the host. `EntryAccessGate` (the public surface) is a different question.
        CascadePolicyRegistrar::register(BeamUxEntry::class);

        // A Release reads by the rule its resource declares, `ux.author`, whether listed or opened (EP-15 receipt).
        Gate::policy(Models\BeamUxRelease::class, Release\ReleasePolicy::class);
        Gate::policy(Models\BeamUxRedirect::class, Redirect\RedirectPolicy::class);

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
                Doctor\RecordPageIsolationAudit::class,
            );
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                Doctor\AuthoredComponentAudit::class,
            );
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

            // ADR-0213's sibling check: an entry naming a layout/template that resolves to neither a
            // registered component nor another entry. Chrome resolves client-side, so an unresolvable
            // name renders the fallback shell behind a 200 — a guide silently without its rail.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxChromeAudit::class,
            );

            // ADR-0233: FAILS while beam.ux.mdx.holds_blocks is on and any stored MDX page still holds source (the
            // gate is only for a converted host, d9), and reports the frontmatter keys published pages keep (d3).
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxMdxPagesAudit::class,
            );

            // EP-07 (ADR-0228 d6): each realm's Manifest Profile against the host's JS registry snapshot, both ways.
            // Without a snapshot it is INCONCLUSIVE, reported as a warning and never as a pass (WP-EP07 ruling).
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxManifestRegistryAudit::class,
            );

            // EP-07d: page forms post to the public intake door; its honeypot and the honeypot's field name are host facts.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxIntakeFormAudit::class,
            );

            // theme-entries-and-authoring ticket 07: `ThemeResolver::resolve()` never throws, so a query
            // error in the cascade renders package defaults behind a 200, indistinguishable from "no
            // theme configured". This runs the resolver on read and WARNS (never fails — a host fact)
            // naming the tier and the exception class it swallowed.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxThemeResolutionAudit::class,
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

            // ADR-0209 §2's inverse, measured at beam-docs-satellite ticket 38: the renderer's catch-all
            // is mounted LAST so it shadows nothing — which means everything registered before it wins,
            // including a route the host never wrote. Scribe's unpublished defaults mount an HTML docs UI
            // at `/docs`, the exact URL beam's install seeds the docs subtree at, and the starter 500'd
            // there with a correct entry sitting behind it. Every other check in this package passed.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxRouteShadowAudit::class,
            );

            // ADR-0236's Consequences: a Redirect that stops answering does so silently, the guest getting the uniform
            // 404. This names one that dangles, loops, sits where a page answers, or that a host route serves first.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                Doctor\BeamUxRedirectAudit::class,
            );

            // ADR-0236 d8: a hidden page hides the pages under it, so a structural page left unpublished is named here.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                Doctor\BeamUxHiddenParentAudit::class,
            );

            // beam-docs-satellite 69: a composed URL is not evidence of reachability. `UrlResolver`
            // reported corrected URLs for fifteen www docs guides while `EntryPathResolver` could not
            // see six of the rows — `realms` was NULL, written past `BeamUxEntry::booted()`, and
            // `whereJsonContains` cannot match NULL. The two are NOT inverses; this asserts the round
            // trip, which is the only thing that can see the disagreement.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxReachabilityAudit::class,
            );

            // ADR-0225 decision 2: the Publication Pin is required wherever the entry editor is mounted, and
            // a missing version table or `published_version` column fails at INSTALL. Mounted is read from
            // the route table; a host that mounts no entry history op passes.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxVersionStoreAudit::class,
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

        $this->declareFilterResources();
    }

    /**
     * Registers the entry and mirror queries without replacing host definitions.
     * Beam resolves their backing models from the matching particle declarations.
     */
    protected function declareFilterResources(): void
    {
        if (! $this->app->bound(FilterResourceRegistry::class)) {
            return;
        }

        $registry = $this->app->make(FilterResourceRegistry::class);

        foreach ([
            'beam-ux-entry' => BeamUxEntryData::class,
            'beam-ux-mirror-status' => MirrorStatusRowData::class,
            'beam-ux-sitemap-health' => SitemapHealthRowData::class,
        ] as $key => $data) {
            if (! $registry->has($key)) {
                $registry->registerDefinition(new FilterResourceDefinition(
                    key: $key,
                    data: $data,
                    query: BeamUxEntryResourceQuery::class,
                ));
            }
        }
    }
}
