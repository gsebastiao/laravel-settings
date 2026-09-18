# Laravel Settings

[![License](https://img.shields.io/packagist/l/gsebastiao/laravel-auditable.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-auditable.svg)](composer.json)
[![Laravel Framework](https://img.shields.io/packagist/dependency-v/gsebastiao/laravel-auditable/illuminate/support.svg)](composer.json)
[![Latest Version](https://img.shields.io/packagist/v/gsebastiao/laravel-auditable.svg)](https://packagist.org/packages/gsebastiao/laravel-auditable)

Guarda as configurações da tua aplicação Laravel na base de dados e lê-as com uma linha de código.

```php
Settings::set('app.name', 'Loja do Zé');

setting('app.name'); // 'Loja do Zé'
```

Serve para tudo o que deve poder mudar sem mexer no código: o nome da loja, o email de contacto, o tema que cada utilizador escolheu, o logótipo de cada cliente...

- **Simples** — `get` e `set`, como o `config()` do Laravel.
- **Por utilizador** — cada utilizador pode ter o seu valor; quem não tiver usa o valor geral.
- **Tipos automáticos** — gravas um número e recebes um número; gravas uma lista e recebes uma lista.
- **Rápido** — usa a cache do Laravel, que é limpa sozinha quando gravas.

Requisitos: PHP 8.2+ e Laravel 11, 12 ou 13.

## Índice

- [Instalação](#instalação)
- [Primeiros passos](#primeiros-passos)
- [As chaves: "grupo.nome"](#as-chaves-gruponome)
- [Tipos de valores](#tipos-de-valores)
- [Settings por utilizador](#settings-por-utilizador)
- [Bloquear uma setting](#bloquear-uma-setting)
- [Cache](#cache)
- [Configuração (opcional)](#configuração-opcional)
- [Referência rápida](#referência-rápida)
- [Funcionalidades avançadas (opcionais)](#funcionalidades-avançadas-opcionais)
- [Resolução de problemas](#resolução-de-problemas)
- [Actualizar da versão 1.x](#actualizar-da-versão-1x)

---

## Instalação

```bash
composer require gsebastiao/laravel-settings
```

```bash
php artisan migrate
```

Pronto. Não precisas de publicar nem de configurar nada.

> **Queres mudar alguma opção (nome da tabela, cache, contextos...)?** Não
> precisas de publicar o config: basta acrescentar variáveis `SETTINGS_*` ao
> teu `.env`. A lista completa, com o padrão de cada uma, está em
> [Configuração](#configuração-opcional). Se fores mudar o nome da tabela
> (`SETTINGS_TABLE`), faz isso **antes** do `migrate`.

---

## Primeiros passos

### Gravar e ler

```php
use Gsebastiao\LaravelSettings\Facades\Settings;

Settings::set('app.name', 'Loja do Zé');

Settings::get('app.name');                // 'Loja do Zé'
Settings::get('app.slogan', 'Bem-vindo'); // 'Bem-vindo' — não existe, devolve o valor por defeito
```

Gravar outra vez a mesma chave substitui o valor.

### Nas views (Blade)

Usa o helper `setting()`, que funciona como o `config()`:

```blade
<title>{{ setting('app.name', 'A minha loja') }}</title>
```

### Verificar, apagar e ler um grupo

```php
Settings::has('app.name');    // true
Settings::forget('app.name'); // apaga

Settings::set('mail.from_name', 'Loja do Zé');
Settings::set('mail.from_address', 'geral@loja.co.mz');

Settings::all('mail'); // ['from_address' => 'geral@loja.co.mz', 'from_name' => 'Loja do Zé']
```

### Valores iniciais

Cria os valores iniciais num seeder, como qualquer outro dado da aplicação:

```php
// database/seeders/SettingsSeeder.php
public function run(): void
{
    Settings::set('app.name', 'Loja do Zé');
    Settings::set('app.items_per_page', 20);
}
```

---

## As chaves: "grupo.nome"

Cada setting tem um nome com duas partes separadas por um ponto: **o grupo e o nome**.

```php
'app.name'           // grupo 'app',  nome 'name'
'mail.from_address'  // grupo 'mail', nome 'from_address'
'ui.theme'           // grupo 'ui',   nome 'theme'
```

O grupo serve para organizar e para ler várias settings de uma vez com `Settings::all('mail')`.

Podes usar mais pontos: o corte é feito no **último**. Em `'format.date.short'` o grupo é `'format.date'` e o nome é `'short'`.

Uma chave sem ponto (`'name'`) ou com espaços dá erro com uma mensagem a explicar o formato.

---

## Tipos de valores

O pacote lembra-se do tipo de cada valor:

| Gravas                   | Recebes                         |
| ------------------------ | ------------------------------- |
| `'Loja do Zé'`           | `'Loja do Zé'`                  |
| `14`                     | `14` (inteiro)                  |
| `3.5`                    | `3.5`                           |
| `true`                   | `true`                          |
| `['pt', 'en']`           | `['pt', 'en']`                  |
| `now()`                  | uma data `Carbon`               |
| `Theme::Dark` (enum)     | `'dark'` — usa `Theme::from(...)` |

### Valores de formulários

Tudo o que vem de um formulário chega como texto (`'16'`, `'on'`). Quando a setting **já existe**, o pacote mantém o tipo dela:

```php
Settings::set('ui.font_size', 14);                  // é um inteiro
Settings::set('ui.font_size', $request->font_size); // '16' → guarda 16, continua inteiro
```

Na **primeira** gravação, se o valor vier como texto, indica o tipo com `cast`:

```php
Settings::set('ui.font_size', $request->font_size, cast: 'int');
```

Tipos disponíveis: `string`, `int`, `float`, `bool`, `json` (ou `array`) e `date`.

Se um valor não servir para o tipo (por exemplo `'grande'` numa setting `int`), recebes um erro claro em vez de um valor errado guardado em silêncio. Para `bool` são aceites `true`/`false`, `1`/`0`, `'on'`/`'off'` e `'yes'`/`'no'`.

---

## Settings por utilizador

Cada utilizador pode ter o seu próprio valor:

```php
Settings::forUser($user)->set('ui.theme', 'dark');

Settings::forUser($user)->get('ui.theme', 'light'); // 'dark'
```

Quem nunca escolheu recebe o **valor geral**:

```php
Settings::set('ui.theme', 'light');               // valor geral
Settings::forUser($ana)->set('ui.theme', 'dark'); // só a Ana

Settings::forUser($ana)->get('ui.theme');   // 'dark'
Settings::forUser($bruno)->get('ui.theme'); // 'light' ← usa o valor geral
```

```
Settings::forUser($ana)->get('ui.theme', 'light')

  1. A Ana tem valor próprio?  ── sim → devolve o valor dela
                                └ não ↓
  2. Existe um valor geral?    ── sim → devolve o valor geral
                                └ não → devolve 'light' (o valor por defeito)
```

Por isso **não precisas de criar nada quando um utilizador se regista**: enquanto ele não mudar nada, vê o valor geral.

Nas views, `userSetting()` usa o utilizador autenticado (para visitantes devolve o valor geral):

```blade
<body class="theme-{{ userSetting('ui.theme', 'light') }}">
```

### Exemplo completo: página de preferências

```php
use Gsebastiao\LaravelSettings\Facades\Settings;

class PreferencesController extends Controller
{
    public function edit(Request $request)
    {
        $prefs = Settings::forUser($request->user());

        return view('preferences', [
            'theme'    => $prefs->get('ui.theme', 'light'),
            'fontSize' => $prefs->get('ui.font_size', 14),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'theme'     => 'required|in:light,dark',
            'font_size' => 'required|integer|between:10,24',
        ]);

        // setMany grava tudo numa transacção: ou grava tudo, ou nada.
        Settings::forUser($request->user())->setMany([
            'ui.theme'     => $data['theme'],
            'ui.font_size' => (int) $data['font_size'],
        ]);

        return back()->with('status', 'Preferências guardadas.');
    }
}
```

Quando apagares um utilizador, apaga também as settings dele:

```php
Settings::forgetContext(Settings::userContext($user));
```

---

## Bloquear uma setting

Às vezes um valor tem de ser igual para todos. Bloqueia-o com `lock()`:

```php
Settings::set('app.currency', 'MZN');
Settings::lock('app.currency');

Settings::forUser($user)->get('app.currency');        // 'MZN' — mesmo que tenha outro valor
Settings::forUser($user)->set('app.currency', 'USD'); // erro: SettingLockedException
```

- Podes gravar já bloqueada: `Settings::set('app.currency', 'MZN', options: ['is_locked' => true])`.
- O valor geral continua a poder ser alterado: `Settings::set('app.currency', 'EUR')` funciona.
- `Settings::unlock('app.currency')` desbloqueia. Os valores que os utilizadores tinham voltam a valer.
- `Settings::forUser($user)->isLocked('app.currency')` diz-te se está bloqueada, por exemplo para desactivar o campo no formulário.

---

## Cache

Não tens de fazer nada:

- as settings ficam na cache do Laravel, por isso não há uma consulta à base de dados em cada pedido;
- durante um pedido ficam também em memória, por isso chamar `setting()` dez vezes numa página custa uma só leitura;
- sempre que gravas ou apagas uma setting, a cache é limpa sozinha.

Se alterares a tabela **à mão** (phpMyAdmin, SQL...), limpa a cache:

```bash
php artisan settings:clear-cache
```

(O `php artisan optimize:clear` também o faz.)

---

## Configuração (opcional)

**Não precisas de publicar o ficheiro de configuração.** Para mudar alguma coisa, usa o `.env`:

| Variável                  | Por defeito         | Para quê                                               |
| ------------------------- | ------------------- | ------------------------------------------------------ |
| `SETTINGS_TABLE`          | `settings`          | Nome da tabela. Muda-o **antes** do `migrate`.         |
| `SETTINGS_MANAGERS_TABLE` | `settings_managers` | Tabela das [permissões](#quem-pode-ver-e-editar).      |
| `SETTINGS_CACHE_ENABLED`  | `true`              | `false` desliga a cache.                               |
| `SETTINGS_CACHE_STORE`    | o store por defeito | Um store de `config/cache.php`, por exemplo `redis`.   |
| `SETTINGS_CACHE_TTL`      | `300`               | Segundos até a cache expirar (`null` = nunca expira).  |
| `SETTINGS_CACHE_PREFIX`   | `settings:`         | Prefixo das chaves na cache.                           |
| `SETTINGS_CONTEXT_DEFAULT` | `global`           | Nome do contexto geral.                                |
| `SETTINGS_CONTEXT_USER`   | `user`              | Prefixo do contexto de utilizador (`user:42`).         |
| `SETTINGS_CONTEXT_TENANT` | `tenant`            | Prefixo do contexto de tenant (`tenant:5`).            |
| `SETTINGS_DEFAULT_CAST`   | `string`            | Tipo de uma setting nova sem `cast`: `string`, `int`, `float`, `bool`, `json`, `array`, `date`. |
| `SETTINGS_RESOLVE_ROLE`   | (não definir)       | Classe com `__invoke($user)` que devolve os roles (só para as permissões avançadas). |

> Não deixes uma variável em branco (`SETTINGS_CACHE_STORE=`): o Laravel lê isso
> como texto vazio. Para voltar ao padrão, apaga a linha. Com
> `php artisan config:cache`, corre-o de novo depois de mudar o `.env`.

Se preferires editar o ficheiro, publica a configuração:

```bash
php artisan vendor:publish --tag=settings-config
```

---

## Referência rápida

| Código                                          | O que faz                                                |
| ----------------------------------------------- | -------------------------------------------------------- |
| `Settings::get('grupo.nome', $defeito)`         | Lê um valor                                              |
| `Settings::set('grupo.nome', $valor)`           | Grava (cria ou actualiza)                                |
| `Settings::setMany(['a.b' => 1, 'a.c' => 2])`   | Grava vários, tudo ou nada                               |
| `Settings::has('grupo.nome')`                   | A setting existe?                                        |
| `Settings::forget('grupo.nome')`                | Apaga                                                    |
| `Settings::all('grupo')`                        | Todos os valores de um grupo                             |
| `Settings::find('grupo.nome')`                  | O registo completo (valor, metadata, visibility...)      |
| `Settings::lock()` / `unlock()` / `isLocked()`  | Bloquear e desbloquear                                   |
| `Settings::forUser($user)->...`                 | Todos os métodos acima, para um utilizador               |
| `Settings::forTenant($tenant)->...`             | Todos os métodos acima, para um [tenant](#tenants-saas-multi-cliente) |
| `Settings::forgetContext('user:42')`            | Apaga todas as settings de um contexto                   |
| `Settings::flushCache()`                        | Limpa a cache                                            |
| `setting('grupo.nome', $defeito)`               | Helper para views                                        |
| `userSetting('grupo.nome', $defeito)`           | Helper: utilizador autenticado                           |
| `tenantSetting('grupo.nome', $defeito, tenantId: 5)` | Helper: tenant                                      |

Preferes injecção de dependências em vez da facade? Usa o contrato `SettingsRepository`:

```php
use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;

public function __construct(private SettingsRepository $settings) {}

$this->settings->get('app.name');
```

---

## Funcionalidades avançadas (opcionais)

Tudo o que está acima chega para a maioria das aplicações. As secções seguintes são para casos específicos — podes ignorá-las.

### Tenants (SaaS multi-cliente)

Numa aplicação com vários clientes, cada cliente (tenant) pode ter os seus valores, e cada utilizador os dele:

```php
Settings::set('ui.logo', 'logos/default.png');                  // geral
Settings::forTenant($company)->set('ui.logo', 'logos/acme.png'); // cliente ACME

Settings::forUser($user, tenant: $user->company_id)->get('ui.logo');
// procura: utilizador → tenant → geral
```

### Contextos

Por baixo, cada valor é guardado num **contexto**: `'global'` (o valor geral), `'user:42'`, `'tenant:5'`. Podes usar contextos teus:

```php
Settings::forContext('shop:3')->set('shop.currency', 'MZN');
```

Todos os métodos aceitam também o parâmetro `context:` — um contexto ou uma lista, do mais específico para o mais geral:

```php
Settings::get('ui.logo', context: 'tenant:5');
Settings::get('ui.logo', context: ['user:42', 'tenant:5']); // user:42 → tenant:5 → global
```

### Opções de uma setting

`set()` aceita opções. Numa setting que já existe, **só mudam as opções que passares**.

| Opção            | Valores                              | Para quê                                                        |
| ---------------- | ------------------------------------ | --------------------------------------------------------------- |
| `is_locked`      | `true` / `false`                     | [Bloquear](#bloquear-uma-setting)                               |
| `metadata`       | array                                | Dados livres, por exemplo para construir formulários            |
| `visibility`     | `hidden` / `readonly` / `editable`   | [Quem pode ver e editar](#quem-pode-ver-e-editar)               |
| `is_inheritable` | `true` / `false`                     | [Copiar para utilizadores novos](#copiar-valores-para-utilizadores-novos) |

Exemplo com `metadata` para desenhar um formulário:

```php
Settings::set('ui.language', 'pt', options: [
    'metadata' => [
        'label'   => 'Idioma',
        'input'   => 'select',
        'options' => ['pt' => 'Português', 'en' => 'English'],
    ],
]);

$setting = Settings::find('ui.language');
$setting->value;             // 'pt'
$setting->metadata['label']; // 'Idioma'
```

### Copiar valores para utilizadores novos

Lembra-te: um utilizador novo **já vê** os valores gerais sem copiar nada. Copia só se quiseres que ele fique com os valores do momento do registo, sem ser afectado por mudanças futuras no valor geral.

Só são copiadas as settings marcadas com `is_inheritable`, e nunca por cima de um valor que o utilizador já tenha:

```php
use Gsebastiao\LaravelSettings\Services\SettingsInheritance;

Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);

$inheritance = app(SettingsInheritance::class);

$inheritance->forUser($user);                       // ['copied' => 1, 'skipped' => 0, 'namespaces' => ['ui']]
$inheritance->forUser($user, tenantId: 5);          // copia do tenant 5 (e do geral)
$inheritance->forUser($user, namespaces: ['ui']);   // só o grupo 'ui'
$inheritance->preview($user);                       // mostra o que seria copiado, sem alterar nada
$inheritance->resetUser($user);                     // apaga os valores do utilizador e copia de novo
```

### Quem pode ver e editar

Útil para construir uma página de configurações onde cada pessoa só vê o que lhe diz respeito.

**1. A regra de cada setting** — a opção `visibility`:

| Valor      | O utilizador...        |
| ---------- | ---------------------- |
| `hidden`   | não vê a setting       |
| `readonly` | vê mas não altera      |
| `editable` | vê e altera (o normal) |

```php
Settings::set('mail.smtp_password', 'segredo', options: ['visibility' => 'hidden']);
```

**2. Excepções por utilizador ou role** — com `SettingManager`. Uma permissão vale no contexto em que é dada:

```php
use Gsebastiao\LaravelSettings\Models\SettingManager;

// O role 'manager' pode VER billing.plan no tenant 5
SettingManager::grant('billing.plan', context: 'tenant:5', type: 'role', id: 'manager');

// O utilizador 42 pode EDITAR mail.from_name
SettingManager::grant('mail.from_name', context: 'global', type: 'user', id: 42, visibility: 'editable');

SettingManager::revoke('billing.plan', context: 'tenant:5', type: 'role', id: 'manager');
```

**3. Perguntar o que o utilizador pode fazer:**

```php
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;

$access = app(SettingsAccessControl::class);

$access->canView('billing.plan', context: 'tenant:5', user: $user); // true
$access->canEdit('billing.plan', context: 'tenant:5', user: $user); // false
$access->visibilityFor('billing.plan', context: 'tenant:5', user: $user); // 'readonly'

// Filtrar uma lista para uma página de configurações
$visiveis = $access->filterVisible(Setting::forNamespace('billing')->global()->get(), $user);
```

Uma setting bloqueada num contexto mais geral aparece como `readonly` nos contextos abaixo.

**Roles:** se usas [spatie/laravel-permission](https://github.com/spatie/laravel-permission), funciona sem configurar nada. Com outra solução, diz ao pacote como obter os roles, no `boot()` do `AppServiceProvider`:

```php
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;

SettingsAccessControl::resolveRolesUsing(fn ($user) => $user->roles->pluck('slug'));
```

A migration cria a tabela `settings_managers` para isto. Se nunca usares permissões, fica vazia e não custa nada.

---

## Resolução de problemas

**Alterei o valor na base de dados e nada mudou.**
Está em cache. Corre `php artisan settings:clear-cache`.

**`Chave inválida: 'name'`**
Falta o grupo. Usa `'app.name'` em vez de `'name'`. Ver [As chaves](#as-chaves-gruponome).

**`SettingLockedException`**
A setting está bloqueada num contexto mais geral, por isso não pode ter um valor próprio aqui. Ver [Bloquear uma setting](#bloquear-uma-setting).

**`Não foi possível gravar 'ui.font_size': o valor 'grande' não serve para o tipo 'int'`**
O valor não corresponde ao tipo da setting. Valida o formulário, ou grava com outro tipo: `Settings::set('ui.font_size', 'grande', cast: 'string')`.

**Já tenho uma tabela `settings` (de outro pacote).**
Antes do `migrate`, põe no `.env`: `SETTINGS_TABLE=app_settings`.

**Os meus utilizadores usam UUID.**
Funciona: os contextos aceitam IDs em texto (`user:9b1d...`). Só a coluna `updated_by` fica vazia, porque guarda apenas IDs numéricos.

**Uso Laravel Octane ou filas.**
Sem problema: a memória interna do pacote é limpa em cada pedido e em cada job.

**Não quero a tabela `settings_managers`.**
Podes ignorá-la. Para não a criar, publica a migration (`php artisan vendor:publish --tag=settings-migrations`), apaga o segundo `Schema::create` e só depois corre o `migrate`. Não mudes o nome do ficheiro publicado.

---

## Actualizar da versão 1.x

Lê o [UPGRADE.md](UPGRADE.md). **Importante:** a 1.x tinha um erro que podia gravar o mesmo valor em todas as settings da tabela; o guia explica como verificar os teus dados.

---

## Testes

```bash
composer install
composer test
```

## Licença

MIT © [Gerson Sebastião Cossa](https://github.com/gsebastiao). Ver [LICENSE](LICENSE).
