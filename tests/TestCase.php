<?php

namespace Gsebastiao\LaravelSettings\Tests;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;
use Gsebastiao\LaravelSettings\SettingsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Settings' => Settings::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function tearDown(): void
    {
        SettingsAccessControl::resolveRolesUsing(null);

        parent::tearDown();
    }

    /** Simula o início de um novo pedido HTTP (ou de um novo job na fila). */
    protected function newRequest(): void
    {
        $this->app->forgetScopedInstances();
    }
}
