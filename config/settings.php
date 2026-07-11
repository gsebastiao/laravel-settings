<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Configurações de cache para as settings. Por defeito usa o driver
    | configurado no Laravel (config/cache.php). Define 'driver' => null
    | para usar o driver por defeito, ou especifica 'redis', 'memcached', etc.
    |
    */
    'cache' => [
        'ttl'    => env('SETTINGS_CACHE_TTL', 300),      // segundos (5 min)
        'prefix' => env('SETTINGS_CACHE_PREFIX', 'settings:'),
        'driver' => env('SETTINGS_CACHE_DRIVER', null),  // null = driver padrão
    ],

    /*
    |--------------------------------------------------------------------------
    | Tabela
    |--------------------------------------------------------------------------
    |
    | Nome da tabela na base de dados. Útil em projetos multi-pacote para
    | evitar colisões.
    |
    */
    'table' => env('SETTINGS_TABLE', 'settings'),

    /*
    |--------------------------------------------------------------------------
    | Controlo de acesso (opcional)
    |--------------------------------------------------------------------------
    |
    | Nome da tabela pivot para gestão granular por utilizador/role. É criada
    | automaticamente pela mesma migration de 'settings', mas o USO é
    | opcional — enquanto nunca chamares SettingManager::grant(), fica vazia
    | e o pacote usa apenas o campo 'visibility' da tabela settings.
    | Ver database/migrations/..._create_settings_tables.php
    |
    */
    'managers_table' => env('SETTINGS_MANAGERS_TABLE', 'settings_managers'),

    /*
    |--------------------------------------------------------------------------
    | Resolução de roles do utilizador
    |--------------------------------------------------------------------------
    |
    | O SettingsAccessControl precisa de saber quais roles um utilizador tem
    | para verificar a tabela settings_managers. Por defeito assume que o
    | model User tem um método getRoleNames(): array (compatível com
    | spatie/laravel-permission). Podes sobrepor com um closure próprio:
    |
    |   'resolve_roles' => fn ($user) => $user->roles->pluck('slug')->all(),
    |
    | Se o teu projecto não usa roles (cenário simples), ignora esta opção —
    | só é chamada se a tabela settings_managers existir e tiver registos.
    |
    */
    'resolve_roles' => null,

    /*
    |--------------------------------------------------------------------------
    | Contextos
    |--------------------------------------------------------------------------
    |
    | Define o contexto padrão e os prefixos usados pelo SettingsService.
    | A hierarquia de resolução vai do mais específico ao mais geral:
    |   user:42 → tenant:5 → global
    |
    */
    'contexts' => [
        'default' => 'global',
        'user'    => 'user',    // prefixo: 'user:42'
        'tenant'  => 'tenant',  // prefixo: 'tenant:5'
    ],

    /*
    |--------------------------------------------------------------------------
    | Cast padrão
    |--------------------------------------------------------------------------
    |
    | Tipo de cast usado quando não é especificado ao gravar uma setting.
    | Opções: string | int | float | bool | json | array | date
    |
    */
    'default_cast' => 'string',

];
