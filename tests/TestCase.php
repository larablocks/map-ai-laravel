<?php

namespace larablocks\MapAi\Tests;

use larablocks\MapAi\MapAiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MapAiServiceProvider::class,
        ];
    }
}
