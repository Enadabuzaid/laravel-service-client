<?php

namespace Enadstack\ServiceClient\Tests;

use Enadstack\ServiceClient\ServiceClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ServiceClientServiceProvider::class];
    }
}
