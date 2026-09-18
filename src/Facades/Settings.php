<?php

namespace Gsebastiao\LaravelSettings\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Acesso rápido às settings.
 *
 *   Settings::set('app.name', 'Loja do Zé');
 *   Settings::get('app.name', 'Minha App');
 *   Settings::forUser($user)->get('ui.theme', 'light');
 *
 * @method static mixed get(string $dotKey, mixed $default = null, string|array|null $context = null)
 * @method static \Gsebastiao\LaravelSettings\Models\Setting|null find(string $dotKey, string|array|null $context = null)
 * @method static bool has(string $dotKey, string|array|null $context = null)
 * @method static \Illuminate\Support\Collection all(string $namespace, string|array|null $context = null)
 * @method static \Gsebastiao\LaravelSettings\Models\Setting set(string $dotKey, mixed $value, string|array|null $context = null, ?string $cast = null, array $options = [])
 * @method static void setMany(array $values, string|array|null $context = null)
 * @method static bool forget(string $dotKey, string|array|null $context = null)
 * @method static int forgetContext(string $context)
 * @method static void lock(string $dotKey, string|array|null $context = null)
 * @method static void unlock(string $dotKey, string|array|null $context = null)
 * @method static bool isLocked(string $dotKey, string|array|null $context = null)
 * @method static \Gsebastiao\LaravelSettings\Support\ContextualSettings forUser(mixed $user = null, mixed $tenant = null)
 * @method static \Gsebastiao\LaravelSettings\Support\ContextualSettings forTenant(mixed $tenant)
 * @method static \Gsebastiao\LaravelSettings\Support\ContextualSettings forContext(string|array $context)
 * @method static void flushCache()
 * @method static string globalContext()
 * @method static string userContext(mixed $user = null)
 * @method static string tenantContext(mixed $tenant)
 *
 * @see \Gsebastiao\LaravelSettings\Services\SettingsService
 */
class Settings extends Facade
{
    /**
     * O serviço é "scoped" (um novo por pedido/job, para a memória interna
     * não passar de um pedido para o outro), por isso a facade não o guarda.
     */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return 'settings';
    }
}
