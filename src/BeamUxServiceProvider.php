<?php

namespace Splicewire\Beam\Ux;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Sitemap\SitemapSourceRegistry;
use Splicewire\Beam\Storage\DiskStorageDriver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Storage\StackedStorageDriver;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Codec\CssBodyCodec;
use Splicewire\Beam\Ux\Codec\MdxBodyCodec;
use Splicewire\Beam\Ux\Codec\TsxBodyCodec;
use Splicewire\Beam\Ux\Console\EnrichPageSchemasCommand;
use Splicewire\Beam\Ux\Console\RegisterFromDiskCommand;
use Splicewire\Beam\Ux\Console\ScaffoldCommand;
use Splicewire\Beam\Ux\Console\SeedNavCommand;
use Splicewire\Beam\Ux\Console\UpdateFromNewerCommand;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Database\Seeders\NavSeeder;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Disk\UpdateFromNewer;
use Splicewire\Beam\Ux\Doctor\BeamUxMigrationsAudit;
use Splicewire\Beam\Ux\Entitlements\BeamUxRealmGrantable;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxEntryBodyController;
use Splicewire\Beam\Ux\Http\Controllers\BeamUxMirrorStatusController;
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
use Splicewire\Beam\Ux\Storage\GitRepoRegistrar;
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
 * The BeamUx authoring engine's provider (charter S0). Paid `splicewire/*` tier (ADR-0092): it homes
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
                // The GitRepo cache table (mirror-status-ui ticket 02) — same publish-only stub
                // convention, doctor/install manifest coverage inherited for free (both audit the
                // package's migrations generically via the publish tag, not per file).
                'shared/create_git_repos_table',
            ]);
    }

    public function packageRegistered(): void
    {
        // Merge the entry-body route config (api_root / route_name) as `config('beam.ux.*')`, so the
        // beamUxEntries() macro reads a config-driven, env-overridable prefix instead of a hardcoded one.
        $this->mergeConfigFrom(__DIR__.'/../config/beam/ux.php', 'beam.ux');

        $this->registerCodecs();
        $this->registerInference();
        $this->registerPlacement();
        $this->registerStorage();
        $this->registerContainment();
        $this->registerSitemap();
        $this->registerDisk();
        $this->registerEntitlements();
    }

    public function packageBooted(): void
    {
        $this->bootSitemap();
        $this->registerEntryWorkflow();
        $this->bootCommands();
        $this->bootRouteMacro();
        $this->registerThemeSchemas();

        // beam-ux is an "operator" of the estate-wide publish-only stub migrations convention — self
        // registers the doctor/operator check on ITS OWN migrations DOWN into beam-core's aggregation
        // manifest, guarded on the manifest being bound (a host predating it still boots beam-ux fine).
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-ux',
                BeamUxMigrationsAudit::class,
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

        // Self-registers its NavSeeder (a thin db:seed adapter over splicewire:beam:ux:seed-nav) DOWN into
        // beam-core's seed manifest, so one `splicewire:beam:seed` restamps the content nav alongside every
        // other package's seeders after a migrate:fresh. Gated by `beam.ux.seed_nav` (default true); order 20
        // so it runs after the accounts substrate. Guarded on the manifest being bound.
        if ($this->app->bound(BeamSeedManifest::class)) {
            $this->app->make(BeamSeedManifest::class)->register(
                package: 'splicewire/laravel-beam-ux',
                seederClass: NavSeeder::class,
                order: 20,
                configGate: 'beam.ux.seed_nav',
            );
        }

        // Self-describes this package's own registry-shaped singletons into beam-core's ManifestIndex
        // (`splicewire:beam:manifests`) — found live: laravel-beam-ux had never described anything, so
        // UndescribedRegistryAudit's membership-scoped ratchet had never scanned it at all (a package
        // that has never described anything is not scanned; one that has is held to describing ALL of
        // them). Describing CodecRegistry alone would have opted the package INTO that gate while
        // leaving PlacementResolver/StorageDriverResolver — both registry-shaped by the exact same
        // register()+resolve() test, both already documented as "the same shape as CodecRegistry" in
        // their own class docblocks — silently undescribed, immediately failing the build. All three or
        // none. Guarded on the index being bound (a host predating it still boots beam-ux fine).
        if ($this->app->bound(ManifestIndex::class)) {
            $index = $this->app->make(ManifestIndex::class);
            $pkg = 'splicewire/laravel-beam-ux';

            $index->describe(new ManifestDescriptor(
                name: 'CodecRegistry',
                of: 'BodyCodec implementations by UxFormat — how a BeamUxEntry body compiles to/from its raw source',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::PickOne,
                registerHint: 'app(CodecRegistry::class)->register(new YourBodyCodec) from your provider',
                where: CodecRegistry::class,
                package: $pkg,
            ));
            $index->describe(new ManifestDescriptor(
                name: 'PlacementResolver',
                of: 'FilePlacement strategies by name — the disk mirror path an entry materializes to',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::PickOne,
                registerHint: "app(PlacementResolver::class)->register('name', \$filePlacement) from your provider",
                where: PlacementResolver::class,
                package: $pkg,
            ));
            $index->describe(new ManifestDescriptor(
                name: 'StorageDriverResolver',
                of: 'StorageDriver implementations by name — where a BeamUxEntry particle body is read/written',
                seam: ManifestSeam::SingletonAccumulator,
                arity: ManifestArity::PickOne,
                registerHint: "app(StorageDriverResolver::class)->register('name', \$storageDriver) from your provider",
                where: StorageDriverResolver::class,
                package: $pkg,
            ));
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
     *                              GET  beam/ux/mirror-status       → show   (beam.ux.mirror-status.show)
     *
     * The mirror-status route rides the SAME macro/prefix (not a separate one) — it's the read half of
     * the same "what did the disk mirror do" question the entry-body routes' `update()` already answers
     * per-save; a host mounting one wants both.
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
            $this->get("{$apiRoot}/mirror-status", [BeamUxMirrorStatusController::class, 'show'])
                ->name("{$routeName}mirror-status.show");
        });
    }

    /**
     * The **explicit-operator-batch** disk seam (charter S8, `beamux-build/issues/05`). Binds the
     * format-aware {@see RegisterFromDisk} recognizer/path-envelope deriver + the two batch operations —
     * {@see RegisterEntriesFromDisk} (scan → create → S9-infer-at-import) and {@see UpdateFromNewer}
     * (config-gated, OFF by default). There is deliberately NO ambient filesystem watcher: every inbound
     * disk→DB flow is one of these operator-run batches. Paid `splicewire/*` (ADR-0092): the batch
     * orchestration over the storage port is paid; the particle records the body rides are free beam-core.
     */
    protected function registerDisk(): void
    {
        $this->app->singleton(RegisterFromDisk::class);
        $this->app->singleton(RegisterEntriesFromDisk::class);
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
            EnrichPageSchemasCommand::class,
            Console\PnpmOverridesCommand::class,
        ]);
    }

    /**
     * The prop→draft-schema inference seam (charter S9, `beamux-build/issues/06`). Binds the
     * deterministic {@see TsxPropInference} parser + the {@see InferDraftSchema} authoring action as
     * singletons — the clean service port S8's `register-from-disk` batch resolves to infer a DRAFT
     * schema for a freshly-registered `component` at import. Paid `splicewire/*` (ADR-0092): the
     * inference engine + the draft schema-ref it writes are the paid arm; the particle body is free
     * beam-core.
     */
    protected function registerInference(): void
    {
        $this->app->singleton(Inference\TsxPropInference::class);
        $this->app->singleton(Inference\InferDraftSchema::class);
    }

    /**
     * The {@see CodecRegistry} — the format→codec dispatch seam (ADR-0164). Bound as a singleton
     * seeded with the TSX + MDX + CSS codecs. The dispatch is paid `splicewire/*`; the MDX codec's
     * engine is the free-tier `laravel-beam-mdx` arm, folded in (not deleted) via {@see MdxBodyCodec}.
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
     * The {@see PlacementResolver} — the paid `FilePlacement` selection seam (charter S2, ADR-0165).
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
            $particle = new ParticleStorageDriver($app->make(ParticleWriter::class));
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
        // GitRepoRegistrar's (mirror-status-ui ticket 02) — batched by repo root, not spawned per file.
        $this->app->singleton(GitRepoRegistrar::class);
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
     * free-tier `rushing/laravel-data-nav` `NavTree`). Both singletons — the resolver is stateless; the
     * `BeamUxEntry::url()` accessor resolves the bound instance. Multiplicity IS built (ticket 03): an
     * entry's `realms` fallback stack means it can be reachable in several realms at once.
     */
    protected function registerContainment(): void
    {
        $this->app->singleton(UrlResolver::class, fn () => new UrlResolver);
        $this->app->singleton(NavProjector::class, fn ($app) => new NavProjector($app->make(UrlResolver::class)));
    }

    /**
     * The **sitemap** seam (charter S5, ADR-0166). beam-ux is the arm's first-class
     * consumer: it binds the two gate ports the {@see EntrySitemapSource} composes,
     * then registers that source onto the free-tier `laravel-beam-sitemap`
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
     * {@see WorkflowBindingRegistry}. Guarded on the free-tier
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
