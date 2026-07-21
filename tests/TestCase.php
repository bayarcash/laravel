<?php

namespace Bayarcash\Laravel\Tests;

use Bayarcash\Laravel\BayarcashServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BayarcashServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bayarcash.token', 'test-token');
        $app['config']->set('bayarcash.secret_key', 'test-secret');
        $app['config']->set('bayarcash.sandbox', true);
        $app['config']->set('bayarcash.persistence', true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
