<?php

namespace Gsebastiao\LaravelSettings\Contracts;

use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Support\ContextualSettings;
use Illuminate\Support\Collection;

/**
 * Interface pública do serviço de settings (o que o Settings:: e o helper
 * setting() usam).
 *
 * Nos parâmetros $context podes passar:
 *   - null                    → contexto global
 *   - 'user:42'               → esse contexto, com fallback para o global
 *   - ['user:42', 'tenant:5'] → vários, do mais específico para o mais geral
 *
 * As leituras procuram na cadeia inteira; as escritas vão para o primeiro.
 *
 * Para usar uma implementação própria, num service provider:
 *   $this->app->scoped(SettingsRepository::class, MinhaImplementacao::class);
 */
interface SettingsRepository
{
    /** Valor da setting, ou $default (pode ser uma função) se não existir. */
    public function get(string $dotKey, mixed $default = null, string|array|null $context = null): mixed;

    /** O registo completo (valor, metadata, visibility...), ou null. */
    public function find(string $dotKey, string|array|null $context = null): ?Setting;

    /** Existe um valor para a chave (mesmo que seja null)? */
    public function has(string $dotKey, string|array|null $context = null): bool;

    /** Todas as settings de um grupo: ['nome' => valor, ...]. */
    public function all(string $namespace, string|array|null $context = null): Collection;

    /**
     * Cria ou actualiza uma setting.
     *
     * $cast: string|int|float|bool|json|array|date (null = deduzido do valor).
     * $options: is_locked, is_inheritable, visibility, metadata — só as
     * opções que passares são alteradas.
     */
    public function set(
        string $dotKey,
        mixed $value,
        string|array|null $context = null,
        ?string $cast = null,
        array $options = [],
    ): Setting;

    /** Grava várias settings numa só transacção: ['ui.theme' => 'dark', ...]. */
    public function setMany(array $values, string|array|null $context = null): void;

    /** Apaga a setting do contexto. Devolve false se não existia. */
    public function forget(string $dotKey, string|array|null $context = null): bool;

    /** Apaga todas as settings de um contexto (ex.: ao apagar um utilizador). */
    public function forgetContext(string $context): int;

    /** Impede contextos mais específicos de terem valor próprio. */
    public function lock(string $dotKey, string|array|null $context = null): void;

    public function unlock(string $dotKey, string|array|null $context = null): void;

    /** A setting está bloqueada para este contexto? */
    public function isLocked(string $dotKey, string|array|null $context = null): bool;

    /** Settings de um utilizador (e opcionalmente do seu tenant). */
    public function forUser(mixed $user = null, mixed $tenant = null): ContextualSettings;

    public function forTenant(mixed $tenant): ContextualSettings;

    public function forContext(string|array $context): ContextualSettings;

    /** Limpa toda a cache das settings. */
    public function flushCache(): void;
}
