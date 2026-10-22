<?php

namespace Splicewire\Beam\Ux\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use RuntimeException;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Sitemap\BeamSitemapServiceProvider;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('data.name_mapping_strategy.input', CamelCaseMapper::class);
        $app['config']->set('data.structure_caching.enabled', false);
        // Actual Git tests opt in only after admitting their own disposable repository root.
        $app['config']->set('beam.ux.recording.git', PHP_BINARY.'-beam-ux-tests-no-git');
    }

    private ?string $ownedFileRoot = null;

    private ?string $fileRootOwner = null;

    /** Portable non-Git file controls run in ordinary CI, outside every checkout. */
    protected function ownedFileFixtureRoot(): string
    {
        if ($this->ownedFileRoot !== null) {
            return $this->ownedFileRoot;
        }
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            throw new RuntimeException('A canonical temporary directory is required.');
        }
        for ($parent = $temporary; $parent !== dirname($parent); $parent = dirname($parent)) {
            if (file_exists($parent.'/.git')) {
                throw new RuntimeException('File fixtures cannot be created inside a checkout.');
            }
        }
        $this->ownedFileRoot = $temporary.'/beam-ux-files-'.bin2hex(random_bytes(12));
        $this->fileRootOwner = bin2hex(random_bytes(16));
        if (! mkdir($this->ownedFileRoot, 0700)) {
            throw new RuntimeException('The owned file fixture cannot be created.');
        }
        file_put_contents($this->ownedFileRoot.'/.beam-ux-file-owner', $this->fileRootOwner);

        return $this->ownedFileRoot;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownedFileRoot !== null) {
                if (is_link($this->ownedFileRoot) || realpath($this->ownedFileRoot) !== $this->ownedFileRoot
                    || file_get_contents($this->ownedFileRoot.'/.beam-ux-file-owner') !== $this->fileRootOwner) {
                    throw new RuntimeException('File fixture ownership changed; preserve it for inspection.');
                }
                if (! (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->ownedFileRoot)) {
                    throw new RuntimeException('Owned file fixture cleanup failed; preserve it for inspection.');
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * beam-ux boots on beam-core (its one required rung, ADR-0092 vendor seam).
     * The beam-core deps below are the same set beam-core's own TestCase declares — they are declared
     * dependencies DOWN, not rungs above beam-ux.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamUxServiceProvider::class,
            // The sibling sitemap arm (ADR-0166): beam-ux registers EntrySitemapSource
            // onto its registry and reads its SitemapBaseUrlResolver port.
            BeamSitemapServiceProvider::class,
            // The sibling workflows engine (S6): the entry is an OPTIONAL MarkingSubject of it;
            // beam-ux registers its publish lifecycle blueprint and reads the LifecycleService.
            BeamWorkflowsServiceProvider::class,
            BeamServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            // Free-tier nav primitive (ADR-0092): beam-ux's containment NavProjector projects a realm's
            // tree into this package's NavTree rather than rebuilding one (S3, ADR-0165).
            DataNavServiceProvider::class,
            // The registry kernel's Laravel arm (registry-kernel ticket 38 / 27 D3). Testbench does not
            // auto-discover, and WITHOUT it `RegistryIndex` is still auto-resolvable but UNSHARED — every
            // `describe()` lands on a throwaway and the suite stays green over an empty index. The
            // tripwire in `RegistryConformanceTest` is what keeps this line honest.
            PopcornServiceProvider::class,
        ];
    }

    /**
     * Runs every migration `splicewire/laravel-beam-workflows` declares, in its declared order.
     *
     * Those migrations ship publish-only (`runsMigrations` stays false), so Testbench never loads
     * them. The list comes from `BeamWorkflowsServiceProvider::configurePackage()`'s own
     * `->hasMigrations([...])` rather than a hand-built copy here: a hand-built copy is what broke
     * when workflows began writing `workflow_transition_facts` on every applied transition. A
     * migration the package adds later reaches this harness with no edit. Same technique as
     * `splicewire/laravel-beam-market`'s `tests/TestCase.php::runPackageSharedMigrationStubs()`.
     */
    protected function runBeamWorkflowsMigrations(): void
    {
        $package = new Package;
        $package->setBasePath(dirname((new ReflectionClass(BeamWorkflowsServiceProvider::class))->getFileName()));

        (new BeamWorkflowsServiceProvider($this->app))->configurePackage($package);

        if ($package->migrationFileNames === []) {
            throw new RuntimeException('BeamWorkflowsServiceProvider declares no migrations; this harness cannot build the workflows schema.');
        }

        foreach ($package->migrationFileNames as $name) {
            $migration = $package->basePath("/../database/migrations/{$name}.php");

            if (! file_exists($migration)) {
                $migration .= '.stub';
            }

            if (! file_exists($migration)) {
                throw new RuntimeException("BeamWorkflowsServiceProvider declares migration [{$name}], but no file exists at [{$migration}].");
            }

            (require $migration)->up();
        }
    }
}
