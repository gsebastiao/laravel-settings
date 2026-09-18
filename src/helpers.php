<?php

use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * Lê uma setting — como o config() do Laravel.
     *
     *   setting('app.name')                        // valor ou null
     *   setting('app.name', 'Minha App')           // com valor por defeito
     *   setting('ui.theme', 'light', 'user:42')    // num contexto
     *   setting()->set('app.name', 'Loja do Zé')   // sem argumentos: o serviço
     *
     * @return ($key is null ? SettingsRepository : mixed)
     */
    function setting(?string $key = null, mixed $default = null, string|array|null $context = null): mixed
    {
        /** @var SettingsRepository $settings */
        $settings = app('settings');

        return $key === null ? $settings : $settings->get($key, $default, $context);
    }
}

if (! function_exists('userSetting')) {
    /**
     * Lê uma setting do utilizador autenticado (ou do $user indicado), com
     * fallback para o valor global. Para visitantes devolve o valor global.
     *
     *   userSetting('ui.theme', 'light')
     *   userSetting('ui.theme', 'light', user: $user)
     */
    function userSetting(string $key, mixed $default = null, mixed $user = null): mixed
    {
        $user ??= SettingsService::authenticatedUserId();

        return setting($key, $default, $user === null ? null : SettingsService::userContext($user));
    }
}

if (! function_exists('tenantSetting')) {
    /**
     * Lê uma setting de um tenant, com fallback para o valor global.
     *
     *   tenantSetting('ui.logo', 'logo.png', tenantId: 5)
     */
    function tenantSetting(string $key, mixed $default = null, mixed $tenantId = null): mixed
    {
        $context = in_array($tenantId, [null, 0, ''], true) ? null : SettingsService::tenantContext($tenantId);

        return setting($key, $default, $context);
    }
}
