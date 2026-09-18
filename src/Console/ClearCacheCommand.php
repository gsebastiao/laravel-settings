<?php

namespace Gsebastiao\LaravelSettings\Console;

use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Illuminate\Console\Command;

/**
 * php artisan settings:clear-cache
 *
 * Útil depois de alterares a tabela de settings à mão (phpMyAdmin, SQL...).
 */
class ClearCacheCommand extends Command
{
    protected $signature = 'settings:clear-cache';

    protected $description = 'Limpa a cache das settings (gsebastiao/laravel-settings)';

    public function handle(SettingsRepository $settings): int
    {
        $settings->flushCache();

        $this->components->info('Cache das settings limpa.');

        return self::SUCCESS;
    }
}
