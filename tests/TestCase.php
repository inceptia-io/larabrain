<?php

declare(strict_types=1);

namespace Arafat\Brain\Tests;

use Arafat\Brain\BrainServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BrainServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app-brain.enabled', true);
    }
}
