<?php

namespace Splicewire\Beam\Ux\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam-ux boots on beam-core (its one required rung, ADR-0092 paid tier composing the free body).
     * The beam-core deps below are the same set beam-core's own TestCase declares — they are declared
     * dependencies DOWN, not rungs above beam-ux.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamUxServiceProvider::class,
            BeamServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            // Free-tier nav primitive (ADR-0092): beam-ux's containment NavProjector projects the
            // Sitemap tree into this package's NavTree rather than rebuilding one (S3, ADR-0165).
            DataNavServiceProvider::class,
        ];
    }
}
