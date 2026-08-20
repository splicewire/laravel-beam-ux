<?php

namespace Splicewire\Beam\Ux\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
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
        ];
    }
}
