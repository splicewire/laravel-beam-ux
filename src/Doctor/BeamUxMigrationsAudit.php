<?php

namespace Splicewire\Beam\Ux\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Ux\BeamUxServiceProvider;

class BeamUxMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-ux';
    }

    protected function serviceProviderClass(): string
    {
        return BeamUxServiceProvider::class;
    }
}
