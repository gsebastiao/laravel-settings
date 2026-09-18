<?php

/*
|--------------------------------------------------------------------------
| gsebastiao/laravel-settings
|--------------------------------------------------------------------------
|
| Nada aqui é obrigatório: o pacote funciona sem publicar este ficheiro.
| Para o alterar: php artisan vendor:publish --tag=settings-config
|
*/

return [

    /*
    | Nome da tabela das settings. Muda-o ANTES de correr a migration — por
    | exemplo, se já tens uma tabela chamada "settings" de outro pacote.
    */
    'table' => env('SETTINGS_TABLE', 'settings'),

    /*
    | Tabela das permissões por utilizador/role (funcionalidade avançada e
    | opcional). Fica vazia se não a usares.
    */
    'managers_table' => env('SETTINGS_MANAGERS_TABLE', 'settings_managers'),

    /*
    | Cache
    |
    | As settings ficam na cache do Laravel para não ir à base de dados em
    | cada pedido. A cache é limpa automaticamente quando gravas uma setting.
    | Se alterares a tabela à mão, corre: php artisan settings:clear-cache
    |
    | enabled → false desliga a cache
    | store   → store de config/cache.php (null = o store por defeito)
    | ttl     → segundos até expirar (null = nunca expira)
    | prefix  → prefixo das chaves na cache
    */
    'cache' => [
        'ttl' => env('SETTINGS_CACHE_TTL', 300),
        'enabled' => env('SETTINGS_CACHE_ENABLED', true),
        'prefix' => env('SETTINGS_CACHE_PREFIX', 'settings:'),
        'store' => env('SETTINGS_CACHE_STORE', env('SETTINGS_CACHE_DRIVER')),
    ],

    /*
    | Nomes dos contextos
    |
    | 'default' é o contexto global (vale para toda a aplicação). Os outros
    | são prefixos: Settings::forUser($user) usa 'user:42' e
    | Settings::forTenant(5) usa 'tenant:5'.
    */
    'contexts' => [
        'user' => env('SETTINGS_CONTEXT_USER', 'user'),
        'tenant' => env('SETTINGS_CONTEXT_TENANT', 'tenant'),
        'default' => env('SETTINGS_CONTEXT_DEFAULT', 'global'),
    ],

    /*
    | Tipo usado quando gravas texto numa setting nova sem indicar `cast`.
    | Opções: string, int, float, bool, json, array, date
    */
    'default_cast' => env('SETTINGS_DEFAULT_CAST', 'string'),

    /*
    | Como obter os roles de um utilizador (só para as permissões avançadas).
    |
    | null → usa $user->getRoleNames() se existir (spatie/laravel-permission).
    |
    | Para outra solução, o mais simples é chamar no boot() do AppServiceProvider:
    |
    |   SettingsAccessControl::resolveRolesUsing(fn ($user) => $user->roles->pluck('slug'));
    |
    | Aqui também aceita uma classe com __invoke($user) ou [Classe::class, 'metodo'].
    | Não escrevas uma função (fn) neste ficheiro: impede o php artisan config:cache.
    */
    'resolve_roles' => env('SETTINGS_RESOLVE_ROLE', null),

];
