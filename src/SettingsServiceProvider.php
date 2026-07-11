<?php

namespace Gsebastiao\LaravelSettings;

use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;
use Gsebastiao\LaravelSettings\Services\SettingsInheritance;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Mescla a configuração publicável com os valores por defeito do pacote
        $this->mergeConfigFrom(
            __DIR__ . '/../config/settings.php',
            'settings'
        );

        // Singleton — uma instância partilhada com cache interno
        $this->app->singleton(SettingsService::class, function ($app) {
            return new SettingsService(
                cacheTtl:    $app['config']->get('settings.cache.ttl', 300),
                cachePrefix: $app['config']->get('settings.cache.prefix', 'settings:'),
                cacheDriver: $app['config']->get('settings.cache.driver'),
            );
        });

        // Alias curto para resolução via app('settings')
        $this->app->alias(SettingsService::class, 'settings');

        // SettingsInheritance — injeta o SettingsService automaticamente
        $this->app->singleton(SettingsInheritance::class, function ($app) {
            return new SettingsInheritance(
                settings: $app->make(SettingsService::class),
            );
        });

        // SettingsAccessControl — resolve visibilidade final por utilizador.
        // Sem dependências no construtor; funciona mesmo sem settings_managers.
        $this->app->singleton(SettingsAccessControl::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // ── Publicar configuração ─────────────────────────────────────────
            $this->publishes([
                __DIR__ . '/../config/settings.php' => config_path('settings.php'),
            ], 'settings-config');

            // ── Publicar a migration ─────────────────────────────────────────
            // Um único ficheiro cria as duas tabelas (settings + settings_managers).
            // Publica para editar antes de migrar — por exemplo, para remover o
            // bloco da settings_managers se nunca fores usar controlo de acesso
            // granular, ou para adicionar a FK de updated_by.
            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'settings-migrations');

            // ── Publicar tudo de uma vez ──────────────────────────────────────
            $this->publishes([
                __DIR__ . '/../config/settings.php'   => config_path('settings.php'),
                __DIR__ . '/../database/migrations/'  => database_path('migrations'),
            ], 'settings');
        }

        // Carrega a migration automaticamente — cria as duas tabelas
        // (settings + settings_managers) sem precisar de publicar nada.
        //
        // A tabela settings_managers é opcional NO USO, não na criação: fica
        // vazia e sem qualquer custo se nunca chamares SettingManager::grant().
        // Se preferires não a ter de todo na base de dados, publica a
        // migration (comando acima) e remove o segundo Schema::create()
        // antes de correr `php artisan migrate`, ou apaga-a depois com
        // Schema::dropIfExists(config('settings.managers_table')) — o
        // SettingsAccessControl detecta a ausência da tabela em runtime e
        // simplesmente ignora o pivot.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
