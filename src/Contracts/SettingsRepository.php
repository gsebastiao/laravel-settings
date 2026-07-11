<?php

namespace Gsebastiao\LaravelSettings\Contracts;

use Gsebastiao\LaravelSettings\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Contrato que define a interface pública do repositório de settings.
 * Permite substituir a implementação padrão (BD) por outra (JSON, Redis, API)
 * sem alterar o código que consome o serviço.
 *
 * Para usar uma implementação própria:
 *   $this->app->bind(SettingsRepository::class, MinhaImplementacao::class);
 */
interface SettingsRepository
{
    /**
     * Obtém o valor de uma setting resolvendo a hierarquia de contexto.
     */
    public function get(string $dotKey, mixed $default = null, string $context = 'global'): mixed;

    /**
     * Obtém todas as settings de um namespace como Collection ['key' => value].
     */
    public function all(string $namespace, string $context = 'global'): Collection;

    /**
     * Verifica se uma setting existe para o contexto dado.
     */
    public function has(string $dotKey, string $context = 'global'): bool;

    /**
     * Cria ou actualiza uma setting.
     *
     * $options aceita: is_locked (bool), is_inheritable (bool),
     * visibility ('hidden'|'readonly'|'editable'), metadata (array).
     */
    public function set(
        string  $dotKey,
        mixed   $value,
        string  $context = 'global',
        ?string $cast    = null,
        array   $options = []
    ): Setting;

    /**
     * Bloqueia a setting contra overrides por contextos mais específicos.
     */
    public function lock(string $dotKey, string $context = 'global'): void;

    /**
     * Remove uma setting (soft delete).
     */
    public function forget(string $dotKey, string $context = 'global'): void;

    /**
     * Remove todas as settings de um contexto.
     */
    public function forgetContext(string $context): void;
}
