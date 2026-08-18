<?php

namespace Splicewire\Beam\Ux\Tests\Codegen;

use Rushing\Codegen\Laravel\CodegenServiceProvider;
use Splicewire\Beam\Ux\Tests\TestCase;

/**
 * Adds `rushing/laravel-codegen`'s provider ONLY for this test namespace — `rushing/laravel-codegen`
 * is a `require-dev`-only, opt-in integration (see `composer.json`'s `suggest`), not something every
 * one of this package's ~130 other tests should have to boot.
 */
abstract class CodegenIntegrationTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CodegenServiceProvider::class];
    }
}
