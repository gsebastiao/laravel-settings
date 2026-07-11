<?php

namespace Gsebastiao\LaravelSettings\Facades;

use Gsebastiao\LaravelSettings\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed      get(string $dotKey, mixed $default = null, string $context = 'global')
 * @method static Collection all(string $namespace, string $context = 'global')
 * @method static bool       has(string $dotKey, string $context = 'global')
 * @method static Setting    set(string $dotKey, mixed $value, string $context = 'global', ?string $cast = null, array $options = [])
 * @method static void       lock(string $dotKey, string $context = 'global')
 * @method static void       forget(string $dotKey, string $context = 'global')
 * @method static void       forgetContext(string $context)
 * @method static string     globalContext()
 * @method static string     userContext(mixed $user = null)
 * @method static string     tenantContext(int $tenantId)
 *
 * @see \Gsebastiao\LaravelSettings\Services\SettingsService
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'settings';
    }
}
