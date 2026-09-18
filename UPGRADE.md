# Actualizar da versão 1.x para a 2.0

A 2.0 corrige erros graves da 1.x. **Não há nenhuma migration nova**: as tabelas continuam iguais.

```bash
composer require gsebastiao/laravel-settings:^2.0
```

## 1. Verifica os teus dados (importante)

Em **todas** as versões 1.x, `Settings::set()` sobre uma setting **que já existia** gravava o novo valor em **todas as linhas da tabela**: o `UPDATE` era executado sem `WHERE`. O mesmo acontecia ao actualizar uma permissão com `SettingManager::grant()`.

Se alguma vez actualizaste uma setting com a 1.x, verifica a tabela. Muitas linhas com o mesmo `value` e o mesmo `updated_at` são o sinal do problema:

```sql
SELECT value, updated_at, COUNT(*) AS linhas
FROM settings
GROUP BY value, updated_at
HAVING COUNT(*) > 1;
```

Faz o mesmo na tabela `settings_managers`, com a coluna `visibility`. Se encontrares linhas afectadas, repõe-nas a partir de uma cópia de segurança. A 2.0 impede que volte a acontecer.

## 2. Mudanças de comportamento

| Na 1.x | Na 2.0 |
| ------ | ------ |
| `lock()` não tinha efeito. | O valor bloqueado ganha aos contextos mais específicos, e gravar num deles lança `SettingLockedException`. |
| `set()` sem `options` repunha `is_locked`, `is_inheritable`, `visibility` e `metadata` aos valores por defeito. | Só mudam as opções que passares. |
| Actualizar com texto (por exemplo, de um formulário) mudava o tipo para `string`. | O tipo da setting mantém-se. Um valor que não sirva para o tipo lança `InvalidArgumentException`. |
| `has()` era `false` para um valor gravado como `null`. | `has()` diz se a setting existe. |
| `lock()` numa setting inexistente não fazia nada. | Lança `SettingNotFoundException`. |
| `userContext('uuid-...')` ignorava o ID e usava o utilizador autenticado. | Usa o ID que passaste. |
| `userSetting()` sem utilizador autenticado lançava um erro. | Devolve o valor global. |
| `SettingsAccessControl` só encontrava a setting no contexto exacto. | Procura na cadeia de contextos (ex.: `tenant:5` → `global`). As permissões continuam a valer só no contexto em que foram dadas. |
| `SettingsInheritance` copiava o valor do global mesmo quando o tenant tinha um valor próprio não herdável. | Decide primeiro qual o valor que vale e só o copia se for herdável. |
| `forget()`, `forgetContext()` e `revoke()` não devolviam nada. | Devolvem `bool`, o número de settings apagadas e `bool`, respectivamente. |

## 3. Se criaste uma implementação própria de `SettingsRepository`

A interface ganhou métodos (`find`, `setMany`, `unlock`, `isLocked`, `forUser`, `forTenant`, `forContext`, `flushCache`) e os parâmetros `$context` passaram a `string|array|null`. Actualiza a tua classe.

`SettingsService::bustCache()` deixou de existir. A cache é limpa sozinha; se precisares, usa `forgetCachedContexts()` ou `flushCache()`.

## 4. Configuração

- Uma função (`fn`) em `resolve_roles` no `config/settings.php` impede o `php artisan config:cache`. Passa-a para o `boot()` do `AppServiceProvider`:

  ```php
  SettingsAccessControl::resolveRolesUsing(fn ($user) => $user->roles->pluck('slug'));
  ```

- A opção `cache.driver` passou a chamar-se `cache.store` (o nome antigo e a variável `SETTINGS_CACHE_DRIVER` continuam a funcionar). Há uma opção nova: `cache.enabled`.

## 5. Cache

A 2.0 guarda a cache num formato novo, com chaves diferentes, e ignora as entradas da 1.x. Não precisas de fazer nada. Se quiseres libertar o espaço, corre `php artisan cache:clear`.
