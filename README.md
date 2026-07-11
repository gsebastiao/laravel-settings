# Laravel Settings

> Pacote Laravel para gestão de settings com chave composta, hierarquia de contexto (global → tenant → user) e cast dinâmico de valores.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10%2F11%2F12%2F13-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

> **Cresce com a tua app.** Uma aplicação simples usa só `Settings::get()` /
> `Settings::set()` — a migration cria as duas tabelas do pacote, mas
> `settings_managers` fica vazia e sem custo até precisares dela. Uma
> aplicação com multitenancy e controlo de acesso granular activa
> `is_inheritable`, `visibility` e `SettingManager::grant()` sem tocar no
> código já escrito, nem correr uma segunda migration.

---

## Instalação

```bash
composer require gsebastiao/laravel-settings
```

O pacote usa **auto-discovery** — o `SettingsServiceProvider` e o alias `Settings` são registados automaticamente.

Corre a migration:

```bash
php artisan migrate
```

---

## Publicar recursos (opcional)

```bash
# Publicar tudo (config + migrations)
php artisan vendor:publish --tag=settings

# Só configuração
php artisan vendor:publish --tag=settings-config

# Só migrations (para personalizar)
php artisan vendor:publish --tag=settings-migrations
```

---

## Configuração

Após publicar, edita `config/settings.php`:

```php
return [
    'cache' => [
        'ttl'    => env('SETTINGS_CACHE_TTL', 300),      // segundos
        'prefix' => env('SETTINGS_CACHE_PREFIX', 'settings:'),
        'driver' => env('SETTINGS_CACHE_DRIVER', null),  // null = driver padrão
    ],
    'table'        => env('SETTINGS_TABLE', 'settings'),
    'default_cast' => 'string',
    'contexts' => [
        'default' => 'global',
        'user'    => 'user',    // → 'user:42'
        'tenant'  => 'tenant',  // → 'tenant:5'
    ],
];
```

Ou via `.env`:

```env
SETTINGS_CACHE_TTL=600
SETTINGS_TABLE=app_settings
SETTINGS_CACHE_DRIVER=redis
```

---

## Uso

### Helper global `setting()`

```php
// Ler (com default)
$name = setting('general.name', 'Laravel App');

// Ler com contexto do utilizador autenticado
$theme = setting('ui.theme', 'light', context: SettingsService::userContext());

// Shortcut idêntico
$theme = userSetting('ui.theme', 'light');

// Aceder ao serviço (para escrita, lock, etc.)
setting()->set('ui.theme', 'dark', context: 'user:42');
```

### Facade `Settings::`

```php
use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Services\SettingsService;

// Leitura
Settings::get('ui.font_size', 14);
Settings::get('ui.language', 'pt', context: Settings::userContext());
Settings::all('ui', context: 'user:42');   // Collection ['theme' => 'dark', ...]
Settings::has('general.name');

// Escrita
Settings::set('general.name', 'Nova Empresa');
Settings::set('ui.theme', 'dark', context: 'user:42');
Settings::set('ui.language', 'pt', options: [
    'metadata' => ['input' => 'select', 'options' => ['pt', 'en', 'es', 'fr']],
]);

// Gestão
Settings::lock('general.version');         // bloqueia overrides por outros contextos
Settings::forget('ui.theme', context: 'user:42');
Settings::forgetContext('user:42');        // limpar ao apagar utilizador
```

### Injecção de dependência

```php
use Gsebastiao\LaravelSettings\Services\SettingsService;

class ProfileController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function update(Request $request): RedirectResponse
    {
        $ctx = SettingsService::userContext(); // 'user:42'

        $this->settings->set('ui.language',  $request->language,  $ctx);
        $this->settings->set('ui.font_size', $request->font_size, $ctx, cast: 'int');
        $this->settings->set('ui.theme',     $request->theme,     $ctx);

        return back()->with('success', 'Preferências guardadas.');
    }
}
```

---

## Hierarquia de contexto

```
setting('ui.language', 'pt', context: 'user:42')

  1. Existe 'ui.language' @ 'user:42'? ──→ SIM  → devolve 'en'
                                        └→ NÃO  → tenta 'global'
  2. Existe 'ui.language' @ 'global'?  ──→ SIM  → devolve 'pt'
                                        └→ NÃO  → devolve default ('pt')
```

| Context helper | Resultado |
|---|---|
| `SettingsService::globalContext()` | `'global'` |
| `SettingsService::userContext()` | `'user:42'` (autenticado) |
| `SettingsService::userContext(99)` | `'user:99'` |
| `SettingsService::userContext($model)` | `'user:{$model->id}'` |
| `SettingsService::tenantContext(5)` | `'tenant:5'` |

---

## Cast dinâmico

O value é sempre guardado como texto. O campo `cast` controla a deserialização:

| `cast` | Tipo PHP retornado |
|---|---|
| `string` | `string` |
| `int` | `int` |
| `float` | `float` |
| `bool` | `bool` |
| `json` / `array` | `array` |
| `date` | `Carbon` |

Se não especificares `cast`, é inferido automaticamente a partir do valor:

```php
Settings::set('ui.font_size', 14);     // cast=int  (inferido)
Settings::set('ui.dark_mode', true);   // cast=bool (inferido)
Settings::set('ui.tags', ['a', 'b']);  // cast=json (inferido)
```

---

## is_locked — bloquear overrides

```php
// Impede que qualquer contexto mais específico sobrescreva esta setting
Settings::set('general.version', '1.0.0', options: ['is_locked' => true]);
// ou após criar:
Settings::lock('general.version');
```

---

## metadata — dados para o frontend

```php
Settings::set('ui.language', 'pt', options: [
    'metadata' => [
        'input'   => 'select',
        'options' => ['pt', 'en', 'es', 'fr'],
        'label'   => 'Idioma da interface',
    ]
]);

// No controller de admin — construir formulário dinamicamente
$settings  = Settings::all('ui');
$metadatas = \Gsebastiao\LaravelSettings\Models\Setting::forNamespace('ui')->pluck('metadata', 'key');
```

---

## is_inheritable — o que é copiado para novos utilizadores

Por padrão, **nenhuma** setting é herdada quando um utilizador é cadastrado.
Isto evita que configurações internas (versão da app, credenciais de mail,
chaves de API) acabem copiadas para cada utilizador sem necessidade.

Marca explicitamente as settings que fazem sentido no contexto do utilizador:

```php
// Vai ser copiada para user:X ao chamar SettingsInheritance::forUser()
Settings::set('ui.theme', 'dark', options: ['is_inheritable' => true]);

// NÃO vai ser copiada — fica só no global/tenant (comportamento padrão)
Settings::set('general.version', '1.0.0');
Settings::set('mail.smtp_password', 'secret');
```

Ver a secção [Herança de settings](#herança-de-settings-para-novos-utilizadores)
para o fluxo completo de cópia.

---

## visibility — o que o utilizador vê e edita

Cada setting tem uma visibilidade **padrão**, aplicada a qualquer utilizador
que não tenha uma regra específica no controlo de acesso opcional:

| Valor | Efeito |
|---|---|
| `hidden` | O utilizador não sabe que a setting existe |
| `readonly` | O utilizador vê o valor mas não pode alterar |
| `editable` | O utilizador vê e pode personalizar (padrão) |

```php
Settings::set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);
Settings::set('billing.plan',       'pro',    options: ['visibility' => 'readonly']);
Settings::set('ui.theme',           'light',  options: ['visibility' => 'editable']);
```

Numa app simples que só usa `Settings::get()` / `Settings::set()`, isto é
tudo o que precisas — o `visibility` da própria setting já resolve o caso comum
sem qualquer tabela ou configuração extra.

### Controlo de acesso granular (opcional em uso) — `SettingManager` e `SettingsAccessControl`

Se precisares de dar a um utilizador ou role específico acesso diferente do
padrão — por exemplo, um `manager` que pode ver o `billing.plan` normalmente
escondido dos outros utilizadores do tenant — usa a tabela `settings_managers`
para isso.

**A tabela é criada automaticamente** junto com `settings` — a mesma migration
cuida das duas, para evitar dependência entre passos de instalação. Isto **não**
significa que precises de a usar: enquanto nunca chamares `SettingManager::grant()`,
fica vazia e nenhuma query extra é feita — o `visibility` da própria setting
continua a ser a única regra em vigor.

Se preferires nem ter a tabela na base de dados, tens duas opções:

```bash
# Opção 1 — publicar a migration e remover o segundo Schema::create()
# antes de correr `php artisan migrate`
php artisan vendor:publish --tag=settings-migrations

# Opção 2 — já migraste? apaga-a depois, sem afectar o resto do pacote
php artisan tinker
>>> Schema::dropIfExists(config('settings.managers_table'));
```

Nos dois casos, o `SettingManager::tableExists()` detecta a ausência da
tabela em runtime e o `SettingsAccessControl` ignora o pivot automaticamente,
sem lançar excepção.

#### Conceder e revogar acesso

```php
use Gsebastiao\LaravelSettings\Models\SettingManager;

// Dar a um role acesso de leitura a uma setting normalmente escondida
SettingManager::grant('billing.plan', context: 'tenant:5',
    type: 'role', id: 'manager', visibility: 'readonly');

// Dar a um utilizador específico acesso de edição
SettingManager::grant('mail.from_name', context: 'global',
    type: 'user', id: 42, visibility: 'editable');

// Conceder a vários de uma vez
SettingManager::grantMany('ui.theme', context: 'global', managers: [
    ['type' => 'role', 'id' => 'admin'],
    ['type' => 'user', 'id' => 7],
], visibility: 'editable');

// Revogar
SettingManager::revoke('billing.plan', context: 'tenant:5',
    type: 'role', id: 'manager');
```

Quando existe um registo no pivot para o utilizador (directamente, ou através
de um dos seus roles), esse `visibility` **sobrepõe sempre** o da setting —
mesmo que a setting esteja `hidden` por padrão. Match directo por utilizador
tem prioridade sobre match por role.

#### Verificar o que o utilizador pode ver/editar

```php
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;

$access = app(SettingsAccessControl::class);

// Visibilidade final resolvida (considera o pivot se existir)
$access->visibilityFor('billing.plan', context: 'tenant:5', user: auth()->user());
// → 'hidden' | 'readonly' | 'editable'

// Atalhos booleanos
$access->canView('billing.plan', context: 'tenant:5', user: $user);
$access->canEdit('billing.plan', context: 'tenant:5', user: $user);

// Filtrar uma lista de settings para o que o utilizador pode ver
$visible = $access->filterVisible($settings, user: $user);
```

#### Resolução de roles do utilizador

Por padrão, o `SettingsAccessControl` tenta chamar `$user->getRoleNames()`
(compatível com `spatie/laravel-permission`). Se usares outra solução de
roles, define um resolver próprio em `config/settings.php`:

```php
'resolve_roles' => fn ($user) => $user->roles->pluck('slug')->all(),
```



Quando um utilizador é cadastrado, podes copiar as settings do contexto global
(ou do tenant) para o contexto pessoal do utilizador. Depois disso, o utilizador
pode personalizar as suas próprias settings livremente.

### Modo normal (sem multitenancy)

Copia todas as settings de `global` → `user:42`:

```php
use Gsebastiao\LaravelSettings\Services\SettingsInheritance;

class UserController extends Controller
{
    public function store(Request $request, SettingsInheritance $inheritance): RedirectResponse
    {
        $user = User::create($request->validated());

        // Copia todas as settings globais para o contexto do utilizador
        $report = $inheritance->forUser($user);
        // ['copied' => 6, 'skipped' => 0, 'namespaces' => ['ui', 'mail']]

        return redirect()->route('users.index');
    }
}
```

### Modo SaaS multitenant

Copia de `tenant:5` → `global` → `user:42` (tenant sobrepõe global):

```php
$report = $inheritance->forUser($user, tenantId: $user->tenant_id);
```

### Copiar só namespaces específicos

```php
// Só as settings de UI e mail
$report = $inheritance->forUser($user, namespaces: ['ui', 'mail']);

// Tenant + só UI
$report = $inheritance->forUser($user, tenantId: 5, namespaces: ['ui']);
```

### Pré-visualizar antes de copiar (dry run)

```php
$preview = $inheritance->preview($user, tenantId: 5);

// Retorna Collection com o que seria copiado:
// [
//   'ui.theme'    => ['value' => 'dark', 'source' => 'tenant:5', 'action' => 'copy'],
//   'ui.language' => ['value' => 'pt',   'source' => 'global',   'action' => 'copy'],
//   'ui.logo'     => ['value' => '...',  'source' => 'user:42',  'action' => 'skip'],
// ]
```

### Repor settings de um utilizador (reset)

Apaga os registos `user:X` e volta a copiar das fontes:

```php
// Reset total
$inheritance->resetUser($user);

// Reset só do namespace UI
$inheritance->resetUser($user, namespaces: ['ui']);

// Reset multitenant
$inheritance->resetUser($user, tenantId: $user->tenant_id);
```

### Relatório de cópia

O método `forUser()` e `resetUser()` retornam sempre um relatório:

```php
[
    'copied'     => 6,              // settings copiadas
    'skipped'    => 1,              // settings ignoradas (já existiam)
    'namespaces' => ['ui', 'mail'], // namespaces afectados
]
```

---

## Helpers disponíveis

```php
setting('general.name', 'default')                        // ler
setting('ui.theme', 'light', context: 'user:42')         // ler com contexto
setting()                                                 // retorna o serviço

userSetting('ui.font_size', 14)                          // contexto do user autenticado
userSetting('ui.font_size', 14, user: $user)             // contexto de user específico

tenantSetting('ui.logo', 'logo.png', tenantId: 5)       // contexto de tenant
```

---

## Estrutura do pacote

```
gsebastiao/laravel-settings/
├── src/
│   ├── Casts/
│   │   └── SettingValueCast.php         ← cast dinâmico por coluna
│   ├── Contracts/
│   │   └── SettingsRepository.php       ← interface (para substituição)
│   ├── Facades/
│   │   └── Settings.php                 ← facade com docblock para IDE
│   ├── Models/
│   │   ├── Setting.php                  ← Eloquent, PK composta, tabela configurável
│   │   └── SettingManager.php           ← pivot opcional (grant/revoke)
│   ├── Services/
│   │   ├── SettingsService.php          ← lógica principal (get/set/cache)
│   │   ├── SettingsInheritance.php      ← cópia para novos utilizadores
│   │   └── SettingsAccessControl.php    ← resolve visibility final por user
│   ├── SettingsServiceProvider.php      ← registo, publish, migrations
│   └── helpers.php                      ← setting() / userSetting() / tenantSetting()
├── config/
│   └── settings.php
├── database/
│   └── migrations/
│       └── ..._create_settings_tables.php    ← cria settings + settings_managers
├── tests/
│   └── Unit/
│       └── SettingsServiceTest.php
├── composer.json
└── phpunit.xml
```

---

## Testes

```bash
composer test
# ou
./vendor/bin/phpunit
```

---

## Licença

MIT © [Gsebastiao](https://github.com/gsebastiao)
