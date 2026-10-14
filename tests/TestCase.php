<?php

declare(strict_types=1);

namespace Vortech\Factor\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vortech\Factor\FactorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FactorServiceProvider::class];
    }
}
