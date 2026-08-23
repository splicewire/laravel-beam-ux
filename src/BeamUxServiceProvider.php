<?php

namespace Splicewire\Beam\Ux;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Sitemap\SitemapSourceRegistry;
use Splicewire\Beam\Storage\DiskStorageDriver;
use Splicewire\Beam\Storage\GitRepoRegistrar;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Storage\StackedStorageDriver;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Console\CompileEntriesCommand;
use Splicewire\Beam\Ux\Console\EnrichPageSchemasCommand;
use Splicewire\Beam\Ux\Console\RegisterFromDiskCommand;
use Splicewire\Beam\Ux\Console\ScaffoldCommand;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Console\UpdateFromNewerCommand;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Database\Seeders\BeamUxSeeder;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Disk\UpdateFromNewer;
use Splicewire\Beam\Ux\Doctor\BeamUxAccessAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxArtifactAudit;
use Splicewire\Beam\Ux\Doctor\BeamUxMigrationsAudit;
use Splicewire\Beam\Ux\Entitlements\BeamUxRealmGrantable;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController;
use Splicewire\Beam\Ux\Http\Controllers\EntryArtifactController;
use Splicewire\Beam\Ux\Http\Controllers\PublicEntryController;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Http\InertiaEntryRenderer;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Placement\DatePartitionedPlacement;
use Splicewire\Beam\Ux\Placement\DefaultPlacement;
use Splicewire\Beam\Ux\Placement\PlacementResolver;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Sitemap\EntryEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Sitemap\PublicEntitlementGate;
use Splicewire\Beam\Ux\Sitemap\WorkflowMarkingPublishGate;
use Splicewire\Beam\Ux\Storage\MirrorGitStatus;
use Splicewire\Beam\Ux\Storage\PlacedDiskMirror;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Ux\Workflow\EntryPublishLifecycle;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;
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
class BeamUxServiceProvider extends PackageServiceProvider
{
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

        $this->registerCodecs();
        $this->registerCompile();
        $this->registerPublic();
        $this->registerInference();
        $this->registerPlacement();
        $this->registerStorage();
        $this->registerAccess();
        $this->registerContainment();
        $this->registerSitemap();
        $this->registerDisk();
        $this->registerEntitlements();
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

        $this->bootSitemap();
        $this->registerEntryWorkflow();
        $this->bootCommands();
        $this->bootRouteMacro();
        $this->bootPublicRouteMacro();
        $this->registerThemeSchemas();

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

    /**
     * ACC-01: bind beam-ux's OOTB `RealmGrantable` implementation
     * ({@see BeamUxRealmGrantable} — `BeamUxEntry` IS the realm root) onto beam-accounts'
     * `config('beam.accounts.entitlements.realm_grantable')` port, UNLESS the host already set that
     * key itself — mirroring the same conditional-bind idiom `BeamAccountsServiceProvider` uses for
     * `permission-cascade.entitlement_resolver`. This is what turns `DefaultEntitlementResolver`'s
     * grant cascade on for a fresh beam-ux install with no host edit required.
     */
    protected function registerEntitlements(): void
    {
        if (config('beam.accounts.entitlements.realm_grantable') === null) {
            config(['beam.accounts.entitlements.realm_grantable' => BeamUxRealmGrantable::class]);
        }
    }

    /**
     * Register the `Route::beamUxEntries()` macro — the declarative mount for the entry-body transport
     * ({@see BeamUxEntryBodyController}), so a host stops hand-rolling the `beam/ux/entries/{slug}/body`
     * load/save routes app-locally (they were promoted here from splicewire-app, beam-native-controllers P4).
     *
     *   Route::beamUxEntries()  →  GET  beam/ux/entries/{slug}/body → show   (beam.ux.entries.body.show)
     *                              PUT  beam/ux/entries/{slug}/body → update (beam.ux.entries.body.update)
     *
     * The "what did the disk mirror do" READ question (mirror-status-ui ticket 01) used to ride a third
     * route here too; it's now the generic `beam-ux-mirror-status` `#[ParticleResource]`
     * ({@see \Splicewire\Beam\Ux\Data\MirrorStatusRowData}) instead — no bespoke route, controller, or
     * envelope, per the particle doctrine (`docs/agents/particle-doctrine.md` in `laravel-beam`): a host
     * mounts it like any other resource, `Route::particleResource('beam/ux/mirror-status',
     * 'beam-ux-mirror-status', ['only' => ['index']])`, or leaves it REST-unmounted and lets Frame's
     * resource-blind Admin browser serve it off the registry alone.
     *
     * The uri prefix + name stem default from config (`beam.ux.api_root` / `beam.ux.route_name`,
     * env-overridable — ADR-0124), NOT hardcoded, so a host relocates the mount per-deploy without a code
     * change; explicit args still override. Routing (the enclosing middleware group) stays a HOST concern:
     * a host calls this inside its own auth/tenant-guarded group — the middleware IS the write gate (the
     * controller binds a permissive PolicyWriteGate).
     */
    protected function bootRouteMacro(): void
    {
        if (Route::hasMacro('beamUxEntries')) {
            return;
        }

        Route::macro('beamUxEntries', function (
            ?string $apiRoot = null,
            ?string $routeName = null,
        ): void {
            /** @var Router $this */
            $apiRoot ??= config('beam.ux.api_root', 'beam/ux');
            $routeName ??= config('beam.ux.route_name', 'beam.ux.');

            $this->get("{$apiRoot}/entries/{slug}/body", [BeamUxEntryBodyController::class, 'show'])
                ->name("{$routeName}entries.body.show");
            $this->put("{$apiRoot}/entries/{slug}/body", [BeamUxEntryBodyController::class, 'update'])
                ->name("{$routeName}entries.body.update");
        });
    }

    /**
     * The **compile-on-save** seam (ADR-0209 §7). Three producers — the editor save, the disk batch, the
     * `splicewire:beam:ux:compile` backfill — invoke ONE {@see CompileEntryBody} action over a swappable
     * {@see EntryBodyCompiler}, so "what counts as compilable, and as already-current" has exactly one
     * definition for the doctor check to hold everyone to.
     *
     * The default compiler shells out to Node against the HOST's `node_modules`. That is a real
     * deploy-topology commitment and it is made deliberately: every beam-ux host already needs Node to
     * build assets, and paying the compile where content CHANGES beats paying an MDX-compiler download
     * on every page view. A host with a warm build service binds its own compiler and keeps everything
     * above it.
     */
    protected function registerCompile(): void
    {
        $this->app->singleton(EntryArtifactStore::class, fn () => new EntryArtifactStore(
            Storage::disk(config('beam.ux.compile.disk')),
            (string) config('beam.ux.compile.root', 'beam-ux/artifacts'),
        ));

        $this->app->singleton(EntryBodyCompiler::class, fn () => new NodeEntryBodyCompiler(
            binary: (string) config('beam.ux.compile.binary', 'node'),
            script: config('beam.ux.compile.script'),
            workingDirectory: base_path(),
            timeout: (float) config('beam.ux.compile.timeout', 60),
        ));

        $this->app->singleton(CompileEntryBody::class, fn ($app) => new CompileEntryBody(
            $app->make(EntryBodyCompiler::class),
            $app->make(EntryArtifactStore::class),
            $app->make(StorageDriverResolver::class),
        ));
    }

    /**
     * The **public serving** seam (ADR-0209) — resolution, the uniform-404 gate triple, and the response
     * port. Bound here rather than in the macro so a host can resolve any of them directly (a sitemap
     * warmer, a preview route of its own) without mounting the renderer, and so a headless install pays
     * nothing: nothing in this method touches the router.
     *
     * {@see EntryRenderer} is a port because beam-ux does not require Inertia (§6, and it is not in
     * `composer.json`); the shipped binding is the Inertia one, and a non-Inertia host rebinds it.
     */
    protected function registerPublic(): void
    {
        $this->app->singleton(EntryPathResolver::class, fn () => new EntryPathResolver);

        $this->app->singleton(PublicEntryGate::class, fn ($app) => new PublicEntryGate(
            $app->make(EntryPathResolver::class),
            $app->make(EntryAccessResolver::class),
            $app->make(EntryPublishGate::class),
        ));

        $this->app->bind(EntryRenderer::class, InertiaEntryRenderer::class);
    }

    /**
     * Register the `Route::beamUxSite()` macro — the **host-mounted** public renderer (ADR-0209 §2).
     *
     *   Route::beamUxSite('site/entry')  →  GET {artifactRoot}/{entry} → artifact  (beam.ux.site.artifact)
     *                                       GET {path}                 → show      (beam.ux.site.show)
     *
     * The host calls this as the LAST line of its `web.php`. Laravel matches in registration order, so a
     * catch-all registered last shadows nothing the host already claimed — but only the host can
     * guarantee that ordering, and a package silently claiming every unmatched URL in an application is
     * a day of debugging. A host that never calls the macro gets no public surface at all, which is what
     * keeps beam-ux headless-installable without inventing a package boundary to protect it.
     *
     * **Last line of `web.php` is not last registered.** `$reservedPrefixes` exists because that premise
     * is false on any host with deferred route registration: stancl/tenancy groups `routes/tenant.php`
     * inside `$this->app->booted()`, and a host's own route closure may mount more groups after
     * `web.php`. Both register AFTER this catch-all and lose to it by construction. The renderer then
     * runs, resolves nothing, and returns its uniform 404 — which reads as "the API is broken", not as
     * "a catch-all shadowed it", because the route IS registered and `route:list` prints it in a
     * different order than the one that serves requests. Defaults to `['api']` from
     * `beam.ux.site.reserved_prefixes`; the constraint is on the route, because Laravel has no "next
     * route" and a controller that aborts has already swallowed the URL.
     *
     * `$claimRoot` is **off by default**: the direction of travel is a whole site served from entries,
     * but installing beam-ux must never take a host's homepage. `$page` is required and un-defaulted
     * because no beam package ships a rendered page (§6) — the component name is the host's.
     *
     * Middleware stays the host's: a private site mounts this inside its own `auth` group and every
     * entry inherits it (§4). The artifact route is registered FIRST so the catch-all cannot swallow it.
     */
    protected function bootPublicRouteMacro(): void
    {
        if (Route::hasMacro('beamUxSite')) {
            return;
        }

        Route::macro('beamUxSite', function (
            string $page,
            string $realm = BeamUxEntry::REALM_SITE,
            bool $claimRoot = false,
            bool $withNav = true,
            ?string $artifactRoot = null,
            ?string $routeName = null,
            ?array $reservedPrefixes = null,
        ): void {
            /** @var Router $this */
            $artifactRoot ??= config('beam.ux.site.artifact_root', 'beam/ux/artifacts');
            $routeName ??= config('beam.ux.route_name', 'beam.ux.');
            $reservedPrefixes ??= config('beam.ux.site.reserved_prefixes', ['api']);

            $artifactName = "{$routeName}site.artifact";

            $defaults = [
                'beamUxRealm' => $realm,
                'beamUxPage' => $page,
                'beamUxNav' => $withNav,
                'beamUxArtifactRoute' => $artifactName,
            ];

            $apply = function ($route) use ($defaults) {
                foreach ($defaults as $key => $value) {
                    $route->defaults($key, $value);
                }

                return $route;
            };

            // `{version?}` is what earns the immutable cache header (ADR-0209 §7). Without it the
            // artifact sat at a FIXED address served `immutable, max-age=1y`, so a browser that had
            // loaded a page once never asked again and no body edit ever reached a returning reader.
            $apply($this->get("{$artifactRoot}/{entry}/{version?}", EntryArtifactController::class)
                ->name($artifactName));

            if ($claimRoot) {
                $apply($this->get('/', PublicEntryController::class)
                    ->name("{$routeName}site.root"));
            }

            $apply($this->get('{path}', PublicEntryController::class)
                ->where('path', PublicEntryController::pathConstraint($reservedPrefixes))
                ->name("{$routeName}site.show"));
        });
    }

    /**
     * The **explicit-operator-batch** disk seam (charter S8, `beamux-build/issues/05`). Binds the
     * format-aware {@see RegisterFromDisk} recognizer/path-envelope deriver + the two batch operations —
     * {@see RegisterEntriesFromDisk} (scan → create → S9-infer-at-import) and {@see UpdateFromNewer}
     * (config-gated, OFF by default). There is deliberately NO ambient filesystem watcher: every inbound
     * disk→DB flow is one of these operator-run batches. Composition seam (ADR-0092): the batch
     * orchestration over the storage port is beam-ux's; the particle records the body rides are beam-core's.
     */
    protected function registerDisk(): void
    {
        $this->app->singleton(RegisterFromDisk::class);

        // The importer takes the compile action explicitly (ADR-0209 §7's second producer) — a batch
        // that registers pages without compiling them would leave every one of them 404ing until
        // someone ran the backfill.
        $this->app->singleton(RegisterEntriesFromDisk::class, fn ($app) => new RegisterEntriesFromDisk(
            $app->make(RegisterFromDisk::class),
            $app->make(StorageDriverResolver::class),
            $app->make(Inference\InferDraftSchema::class),
            $app->make(EntryAccessGate::class),
            $app->make(CompileEntryBody::class),
        ));
        $this->app->singleton(UpdateFromNewer::class);

        // The frontmatter-stripped raw-`.mdx` reader — seeds an mdxeditor buffer with the existing copy
        // (the vite `@mdx-js` plugin compiles `.mdx`, so the client can't `?raw`-load the source). Root
        // config-driven (`beam.ux.content_path`); a missing file degrades to null.
        $this->app->singleton(Disk\RawMdxReader::class, function () {
            return new Disk\RawMdxReader(
                root: (string) config('beam.ux.content_path', 'resources/js/content'),
            );
        });
    }

    /**
     * Register the two operator-run batch commands (charter S8). Names mirror the package tree (ADR-0167):
     * `splicewire:beam:ux:register-from-disk` + `splicewire:beam:ux:update-from-newer`. Only in console.
     */
    protected function bootCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RegisterFromDiskCommand::class,
            UpdateFromNewerCommand::class,
            ScaffoldCommand::class,
            SeedNavCommand::class,
            CompileEntriesCommand::class,
            EnrichPageSchemasCommand::class,
            Console\PnpmOverridesCommand::class,
        ]);
    }

    /**
     * The prop→draft-schema inference seam (charter S9, `beamux-build/issues/06`). Binds the
     * deterministic {@see TsxPropInference} parser + the {@see InferDraftSchema} authoring action as
     * singletons — the clean service port S8's `register-from-disk` batch resolves to infer a DRAFT
     * schema for a freshly-registered `component` at import. Composition seam (ADR-0092): the
     * inference engine + the draft schema-ref it writes are beam-ux's; the particle body is
     * beam-core's.
     */
    protected function registerInference(): void
    {
        $this->app->singleton(Inference\TsxPropInference::class);
        $this->app->singleton(Inference\InferDraftSchema::class);
    }

    /**
     * The {@see CodecRegistry} — the format→codec dispatch seam (ADR-0164). Bound as a singleton
     * seeded with the TSX + MDX + CSS codecs. beam-ux owns the dispatch; the MDX codec's
     * engine is the sibling `laravel-beam-mdx` arm, folded in (not deleted) via {@see MdxBodyCodec}.
     * {@see CssBodyCodec} is the `UxType::Theme` entry's default (`BeamUxEntry::defaultFormatFor()`) —
     * OTB, not a per-host add-on, since `Theme` is itself a package-level structural type. A host can
     * still `register()` further codecs on the same singleton for formats beyond this seed set.
     */
    protected function registerCodecs(): void
    {
        $this->app->singleton(CodecRegistry::class, function () {
            return (new CodecRegistry)
                ->register(new TsxBodyCodec)
                ->register(new MdxBodyCodec)
                ->register(new CssBodyCodec);
        });
    }

    /**
     * The {@see PlacementResolver} — the `FilePlacement` selection seam (charter S2, ADR-0165).
     * Seeded with the default (`namespace/type/slug.ext`, extension from `format`) + the
     * date-partitioned strategy, plus any `namespace → strategy` map from config. A host registers
     * further strategies on the same singleton. `namespace` derives DISK only, never the URL (two trees).
     */
    protected function registerPlacement(): void
    {
        $this->app->singleton(PlacementResolver::class, function () {
            $resolver = (new PlacementResolver)
                ->register(PlacementResolver::DEFAULT, new DefaultPlacement)
                ->register('date-partitioned', new DatePartitionedPlacement(
                    (string) config('beam.ux.placement.date_root', 'articles'),
                ));

            $map = config('beam.ux.placement.namespaces', []);
            if (is_array($map) && $map !== []) {
                $resolver->mapNamespaces($map);
            }

            return $resolver;
        });
    }

    /**
     * The {@see StorageDriverResolver} — beam-ux as the SECOND consumer of the free-beam-core
     * {@see StorageDriver} seam (generalized from `BeamSchemaRegistry`). The
     * default driver is `Stacked(Particle-primary, Disk-mirror)` (charter): the DB particle is
     * source-of-record, a filesystem disk a materialized projection. The mirror disk is configurable
     * (`beam.ux.storage.disk`, default the framework default disk); a host maps `namespace → driver` for
     * alternate backings.
     */
    protected function registerStorage(): void
    {
        $this->app->singleton(StorageDriverResolver::class, function ($app) {
            // Resolved PER WRITE, not captured: this resolver is a singleton, and a captured writer
            // pins whichever WriteGate was bound when it was first resolved. `AsSystemWriter` rebinds
            // that gate for the duration of a console flow, so a pinned writer made
            // `splicewire:beam:seed` fail with "the write gate refused a write" on any host that had
            // touched this singleton earlier in the same process.
            $particle = new ParticleStorageDriver(fn () => $app->make(ParticleWriter::class));
            $disk = Storage::disk(config('beam.ux.storage.disk'));

            $resolver = (new StorageDriverResolver)
                ->register(StorageDriverResolver::DEFAULT, new StackedStorageDriver(
                    $particle,
                    new DiskStorageDriver($disk),
                ))
                ->register('particle', $particle);

            $map = config('beam.ux.storage.namespaces', []);
            if (is_array($map) && $map !== []) {
                $resolver->mapNamespaces($map);
            }

            return $resolver;
        });

        // The placement-keyed disk mirror — the outbound projection that lands a git-trackable file at the
        // entry's FilePlacement path on Publish (charter S2 / ADR-0165). Its own `beam.ux.storage.mirror_disk`
        // key (DISTINCT from the default Stacked driver's `storage.disk`, which keys by particle uuid): the
        // mirror is the human/git-facing projection and a host opts into it by naming a git-tracked disk.
        // Unset ⇒ a null (no-op) mirror so an un-opted host never grows a disk write.
        $this->app->singleton(PlacedDiskMirror::class, function ($app) {
            $name = config('beam.ux.storage.mirror_disk');
            $disk = ($name === null || $name === '') ? null : Storage::disk($name);

            return new PlacedDiskMirror($disk);
        });

        // Same disk, same degrade-not-fabricate null-when-unconfigured shape as PlacedDiskMirror
        // above — the git-state READ half of what that class WRITES. The actual git shelling is
        // GitRepoRegistrar's (mirror-status-ui ticket 02, now beam-core — its own singleton binding
        // lives there since any beam-core consumer wants the shared in-process cache, not just ux).
        $this->app->singleton(MirrorGitStatus::class, function ($app) {
            $name = config('beam.ux.storage.mirror_disk');
            $disk = ($name === null || $name === '') ? null : Storage::disk($name);

            return new MirrorGitStatus($disk, $app->make(GitRepoRegistrar::class));
        });
    }

    /**
     * The **containment** seam (charter S3, ADR-0165 — the "two trees": containment → URL/nav). Binds the
     * {@see UrlResolver} (composes `segment` DOWN the realm-rooted tree into the public URL,
     * decoupled from `namespace`) and the {@see NavProjector} (projects a realm's tree into a
     * composed-down `rushing/laravel-data-nav` `NavTree`). Both singletons — the resolver is stateless; the
     * `BeamUxEntry::url()` accessor resolves the bound instance. Multiplicity IS built (ticket 03): an
     * entry's `realms` fallback stack means it can be reachable in several realms at once.
     */
    protected function registerContainment(): void
    {
        $this->app->singleton(UrlResolver::class, fn () => new UrlResolver);
        $this->app->singleton(NavProjector::class, fn ($app) => new NavProjector(
            $app->make(UrlResolver::class),
            $app->make(EntryAccessResolver::class),
            $app->make(EntryPublishGate::class),
        ));
    }

    /**
     * The **access** seam (ADR-0212) — the actor-aware third gate that supplies the semantics ADR-0209
     * §5 deferred. It joins the two sitemap gates rather than replacing either: those take no actor and
     * answer *anonymous crawlability*, this one takes an actor and answers *this reader's
     * authorization*, so no re-binding can make one do the other's job.
     *
     * The default binding is {@see TokenAccessGate} — `Splicewire\Tower\Navigation\Gates\AccessGate`
     * descended (ADR-0212 §6), with its two host-specific values (the root role name, and any host
     * tokens beyond ADR-0118's `alias.verb` shape) now config rather than hardcoded. It degrades
     * headless: with no RBAC package present `root` and permission tokens simply deny instead of
     * fataling, so beam-ux stays installable on its own.
     *
     * {@see EntryAccessResolver} is the conjunctive ancestor walk over that gate, bound separately so
     * the renderer and {@see NavProjector} share ONE inheritance rule — a host re-binding the gate
     * never has to reimplement composition.
     */
    protected function registerAccess(): void
    {
        $this->app->singleton(EntryAccessGate::class, fn () => new TokenAccessGate(
            (string) config('beam.ux.access.root_role', TokenAccessGate::DEFAULT_ROOT_ROLE),
            (array) config('beam.ux.access.extra_tokens', []),
        ));

        $this->app->singleton(
            EntryAccessResolver::class,
            fn ($app) => new EntryAccessResolver($app->make(EntryAccessGate::class)),
        );
    }

    /**
     * The **sitemap** seam (charter S5, ADR-0166). beam-ux is the arm's first-class
     * consumer: it binds the two gate ports the {@see EntrySitemapSource} composes,
     * then registers that source onto the sibling `laravel-beam-sitemap`
     * registry at boot. The arm owns the plumbing (contract, registry, controller,
     * command, RouteSitemapSource); beam-ux owns the ENTRY data source.
     *
     * The two gate ports:
     *  - {@see EntryPublishGate} → {@see WorkflowMarkingPublishGate} (S6, real): reads
     *    the entry's persisted `workflow_marking`. An unmanaged entry (no binding) is
     *    published; a managed entry is public only at the published marking. This
     *    replaces the S4/S5 `AlwaysPublishedGate` stub, which is now deleted. Still
     *    re-bindable by a host.
     *  - {@see EntryEntitlementGate} → {@see PublicEntitlementGate} (every entry
     *    public; a gating host re-binds to consult its own entitlement authority).
     */
    protected function registerSitemap(): void
    {
        $this->app->bind(EntryPublishGate::class, WorkflowMarkingPublishGate::class);
        $this->app->bind(EntryEntitlementGate::class, PublicEntitlementGate::class);
    }

    /**
     * The **workflow** seam (charter S6): beam-ux ships the default entry publish lifecycle
     * ({@see EntryPublishLifecycle}) so a host can bind a type into it, and enumerates the entry
     * type keys for the workflows admin. It registers NO binding — the optional-workflow rule: an
     * entry is unmanaged (no state machine) until a host binds its `type` on the
     * {@see WorkflowBindingRegistry}. Guarded on the sibling
     * `laravel-beam-workflows` engine being installed, so beam-ux still boots without it (the
     * publish gate then simply reports every entry unmanaged ⇒ published).
     */
    protected function registerEntryWorkflow(): void
    {
        if (! class_exists(WorkflowRegistry::class) || ! $this->app->bound(WorkflowRegistry::class)) {
            return;
        }

        $this->app->make(WorkflowRegistry::class)
            ->register(EntryPublishLifecycle::DEFINITION, EntryPublishLifecycle::blueprint());

        if (class_exists(WorkflowTypeRegistry::class) && $this->app->bound(WorkflowTypeRegistry::class)) {
            $this->app->make(WorkflowTypeRegistry::class)
                ->register(UxType::Page->value, 'Page')
                ->register(UxType::Component->value, 'Component');
        }
    }

    /**
     * Ship the namespaced theme token schemas (theme-entries-and-authoring ticket 01) into their
     * OWN {@see FilesystemSchemaRegistry} tier — {@see ThemeSchemas::directory()}, NOT through the
     * host's `SchemaRegistry::class` binding, whose `register()` always lands in that host's FIRST
     * configured source (typically the DB tier). Package defaults must live in the FILE tier
     * specifically, so a host's later DB-tier registration of e.g. `theme.site` has something to
     * shadow (`BeamSchemaRegistry`'s whole read-order contract). `register()` is idempotent
     * (fingerprint-checked) and the artifact directory is regenerated from {@see ThemeSchemas} on
     * every boot — never hand-edit the generated `.schema.json` files.
     *
     * Host-side resolvability (JN-15 / ADR-0192 §5 — the formerly documented gap, now closed):
     * the tier is contributed into beam-core's boot-time {@see SchemaSources} registry under the
     * `theme` key, so a host's `BeamSchemaRegistry` resolves these artifacts with NO host edit —
     * appended after the configured sources (lowest precedence) unless the host's
     * `beam.core.schema.sources` names `theme` explicitly to place it. Guarded on the registry
     * class existing so beam-ux still boots against an older beam-core.
     */
    protected function registerThemeSchemas(): void
    {
        $registry = new FilesystemSchemaRegistry(ThemeSchemas::directory());

        foreach (ThemeSchemas::all() as $schema) {
            $registry->register($schema);
        }

        if (class_exists(SchemaSources::class)) {
            $this->app->make(SchemaSources::class)->register(
                'theme',
                fn () => new FilesystemSchemaRegistry(ThemeSchemas::directory()),
            );
        }
    }

    /**
     * Register {@see EntrySitemapSource} onto the arm's {@see SitemapSourceRegistry}.
     * Guarded on the registry existing (the arm being installed) so beam-ux still
     * boots standalone without the sitemap arm. Gated by
     * `beam.ux.sitemap.enabled` (default on).
     */
    protected function bootSitemap(): void
    {
        if (! config('beam.ux.sitemap.enabled', true)) {
            return;
        }

        if (! class_exists(SitemapSourceRegistry::class)) {
            return;
        }

        $this->app->make(SitemapSourceRegistry::class)
            ->register(EntrySitemapSource::class);
    }
}
