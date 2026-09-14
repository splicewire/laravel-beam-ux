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
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Sitemap\BeamSitemapServiceProvider;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
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
