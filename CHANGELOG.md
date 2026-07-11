# Changelog

Todas as alterações relevantes a este pacote estão documentadas aqui.
O formato segue [Keep a Changelog](https://keepachangelog.com/pt/1.0.0/).

## [1.2.0] — 2024-02-15

### Alterado
- **Breaking:** namespace do pacote mudou de `Gsebastiao\Settings` para `Gsebastiao\LaravelSettings` — liberta `Gsebastiao\Settings` para um pacote geral de settings separado do autor
- **Breaking:** as duas migrations (`settings` e `settings_managers`) foram unificadas num único ficheiro `..._create_settings_tables.php`, correndo sempre juntas via `php artisan migrate`
  - `settings_managers` deixa de ser opt-in *na criação* — continua opcional *no uso*: fica vazia e sem custo até chamares `SettingManager::grant()`
  - Quem prefere não ter a tabela na base de dados pode publicar a migration e remover o bloco antes de migrar, ou correr `Schema::dropIfExists()` depois — ver README
  - Removida a tag de publish `settings-managers-migration` (obsoleta, já não existe ficheiro separado para publicar)
- Tabela pivot renomeada de `setting_managers` para `settings_managers` (config `SETTINGS_MANAGERS_TABLE`), consistente com o nome configurável em `config('settings.managers_table')`

### Migração de v1.1.0 para v1.2.0
1. Actualiza todos os `use Gsebastiao\Settings\...` para `use Gsebastiao\LaravelSettings\...` no teu código
2. Se já tinhas corrido a migration antiga de `setting_managers`, renomeia a tabela manualmente: `RENAME TABLE setting_managers TO settings_managers;`
3. Apaga a migration antiga `..._create_setting_managers_table.php` do teu `database/migrations` se a tinhas publicado

## [1.1.0] — 2024-02-01

### Adicionado
- Campo `is_inheritable` (bool, default false) — só settings marcadas explicitamente são copiadas ao cadastrar um utilizador
- Campo `visibility` (`hidden`|`readonly`|`editable`, default `editable`) — controla o que o utilizador vê/edita por padrão
- `SettingsInheritance::forUser()` agora filtra por `is_inheritable=true` e propaga `is_inheritable`/`visibility` para o contexto do utilizador
- `SettingManager` — model pivot **opcional** para controlo de acesso granular por utilizador ou role individual
  - `SettingManager::grant()` / `revoke()` / `grantMany()`
  - Migration separada, nunca carregada automaticamente — opt-in via `vendor:publish --tag=settings-managers-migration`
- `SettingsAccessControl` — resolve a visibilidade final de uma setting para um utilizador, considerando o pivot quando existe
  - `visibilityFor()`, `canView()`, `canEdit()`, `filterVisible()`
  - Compatível com `spatie/laravel-permission` via `getRoleNames()`, ou resolver customizado em `config('settings.resolve_roles')`
- Nova config `managers_table` e `resolve_roles`

### Corrigido
- `SettingsService::set()` agora persiste `is_inheritable` e `visibility` passados em `options` (faltavam no `updateOrCreate`)
- `SettingManager::tableExists()` deixou de usar cache estática de processo, evitando resultados obsoletos em suites de teste onde a BD muda entre casos
- Resolução de roles no pivot deixou de depender de `FIELD()` (exclusivo MySQL), agora portável para SQLite/PostgreSQL

## [1.0.0] — 2024-01-01

### Adicionado
- Chave composta `(namespace, key, context)` — elimina id auto-increment desnecessário
- Hierarquia de contexto: `user:X → tenant:X → global`
- Cast dinâmico via coluna `cast` (string, int, float, bool, json, date)
- Campo `is_locked` para bloquear overrides por contextos mais específicos
- Campo `metadata` (JSON) para dados de apresentação (input type, opções, classes CSS)
- Helpers globais: `setting()`, `userSetting()`, `tenantSetting()`
- Facade `Settings::` com docblock completo para autocomplete de IDE
- Cache configurável por driver, TTL e prefixo
- Nome da tabela configurável via `SETTINGS_TABLE` no `.env`
- Auto-discovery do ServiceProvider e alias
- Suite de testes com Orchestra Testbench
