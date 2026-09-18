<?php

namespace Gsebastiao\LaravelSettings;

use Gsebastiao\LaravelSettings\Console\ClearCacheCommand;
use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;
use Gsebastiao\LaravelSettings\Services\SettingsInheritance;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/settings.php', 'settings');

        // "scoped": uma instância por pedido HTTP / job da fila. O serviço
        // guarda em memória as settings lidas durante o pedido; assim essa
        // memória nunca passa para o pedido seguinte (Octane, filas).
        $this->app->scoped(SettingsService::class, function ($app) {
            $config = $app['config'];
            $ttl = $config->get('settings.cache.ttl', 300);
            $ttl = $ttl === null || $ttl === '' ? null : (int) $ttl;

            return new SettingsService(
                cacheTtl: $ttl,
                cachePrefix: (string) $config->get('settings.cache.prefix', 'settings:'),
                cacheDriver: $config->get('settings.cache.store') ?? $config->get('settings.cache.driver'),
                cacheEnabled: filter_var($config->get('settings.cache.enabled', true), FILTER_VALIDATE_BOOLEAN)
                    && ($ttl === null || $ttl > 0),
            );
        });

        $this->app->scoped(SettingsRepository::class, fn ($app) => $app->make(SettingsService::class));
        $this->app->alias(SettingsRepository::class, 'settings');

        $this->app->scoped(SettingsInheritance::class);
        $this->app->scoped(SettingsAccessControl::class);
    }

    public function boot(): void
    {
        // A migration é carregada automaticamente: basta `php artisan migrate`.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $config = [__DIR__ . '/../config/settings.php' => config_path('settings.php')];
        // O ficheiro publicado mantém o nome original, por isso o Laravel
        // reconhece-o e não corre a migration duas vezes.
        $migrations = [__DIR__ . '/../database/migrations' => database_path('migrations')];

        $this->publishes($config, 'settings-config');
        $this->publishes($migrations, 'settings-migrations');
        $this->publishes($config + $migrations, 'settings');

        $this->commands([ClearCacheCommand::class]);

        // php artisan optimize:clear também limpa a cache das settings (Laravel 11.27+).
        if (method_exists($this, 'optimizes')) {
            $this->optimizes(clear: 'settings:clear-cache', key: 'settings');
        }
    }
}
