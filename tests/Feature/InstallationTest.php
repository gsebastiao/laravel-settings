<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Closure;
use Gsebastiao\LaravelSettings\SettingsServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * O caminho de um iniciante: composer require + php artisan migrate.
 */
class InstallationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }

    public function test_php_artisan_migrate_cria_as_tabelas(): void
    {
        $this->artisan('migrate')->assertSuccessful()->run();

        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertTrue(Schema::hasTable('settings_managers'));
    }

    public function test_tags_de_publicacao(): void
    {
        foreach (['settings', 'settings-config', 'settings-migrations'] as $tag) {
            $this->assertNotEmpty(ServiceProvider::pathsToPublish(SettingsServiceProvider::class, $tag), $tag);
        }
    }

    public function test_configuracao_compativel_com_config_cache(): void
    {
        $config = require __DIR__ . '/../../config/settings.php';

        array_walk_recursive($config, function (mixed $value): void {
            $this->assertNotInstanceOf(Closure::class, $value);
        });

        $this->assertSame('global', config('settings.contexts.default'));
    }
}
