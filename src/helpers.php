<?php

use Gsebastiao\LaravelSettings\Services\SettingsService;

if (! function_exists('setting')) {
    /**
     * Helper global para aceder a settings. Semelhante ao helper config().
     *
     *   // Ler com default
     *   setting('general.name', 'Laravel App')
     *
     *   // Ler com contexto do utilizador autenticado
     *   setting('ui.theme', 'light', context: SettingsService::userContext())
     *
     *   // Aceder ao serviço completo (para set/forget/lock)
     *   setting()->set('ui.theme', 'dark', context: 'user:42')
     *
     * @param  string|null  $key
     * @param  mixed        $default
     * @param  string       $context
     * @return mixed|\Gsebastiao\LaravelSettings\Services\SettingsService
     */
    function setting(
        ?string $key     = null,
        mixed   $default = null,
        string  $context = 'global'
    ): mixed {
        /** @var SettingsService $service */
        $service = app('settings');

        if ($key === null) {
            return $service;
        }

        return $service->get($key, $default, $context);
    }
}

if (! function_exists('userSetting')) {
    /**
     * Lê uma setting no contexto do utilizador autenticado,
     * com fallback automático para o valor global.
     *
     *   userSetting('ui.font_size', 14)
     *   // → tenta 'user:42', depois 'global'
     */
    function userSetting(string $key, mixed $default = null, mixed $user = null): mixed
    {
        return setting($key, $default, context: SettingsService::userContext($user));
    }
}

if (! function_exists('tenantSetting')) {
    /**
     * Lê uma setting no contexto de um tenant, com fallback para global.
     *
     *   tenantSetting('ui.logo', 'logo.png', tenantId: 5)
     */
    function tenantSetting(string $key, mixed $default = null, int $tenantId = 0): mixed
    {
        $context = $tenantId > 0
            ? SettingsService::tenantContext($tenantId)
            : SettingsService::globalContext();

        return setting($key, $default, context: $context);
    }
}
