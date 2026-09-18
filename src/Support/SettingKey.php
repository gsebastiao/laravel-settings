<?php

namespace Gsebastiao\LaravelSettings\Support;

use InvalidArgumentException;

/**
 * Interpreta as chaves "grupo.nome" usadas em toda a API do pacote.
 *
 * Na base de dados a chave fica dividida em duas colunas: `namespace` (o
 * grupo) e `key` (o nome). O corte é feito no ÚLTIMO ponto, o que permite
 * grupos com vários níveis:
 *
 *   'app.name'              → namespace 'app',              key 'name'
 *   'format.date_time.date' → namespace 'format.date_time', key 'date'
 *
 * Até à versão 1.2.2 existiam três cópias deste código e uma delas cortava no
 * PRIMEIRO ponto — por isso o controlo de acesso não encontrava settings com
 * grupos de vários níveis. Agora há uma única implementação.
 */
final class SettingKey
{
    /** Tamanhos máximos das colunas criadas pela migration do pacote. */
    public const MAX_NAMESPACE = 64;

    public const MAX_KEY = 128;

    public const MAX_CONTEXT = 128;

    /**
     * @return array{0: string, 1: string} [namespace, key]
     *
     * @throws InvalidArgumentException quando a chave não tem o formato "grupo.nome"
     */
    public static function parse(string $dotKey): array
    {
        if (preg_match('/^[^.\s]+(\.[^.\s]+)+$/u', $dotKey) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Chave inválida: '%s'. Usa o formato 'grupo.nome' (sem espaços), "
                . "por exemplo 'app.name' ou 'ui.theme'.",
                $dotKey
            ));
        }

        $pos = strrpos($dotKey, '.');
        $namespace = substr($dotKey, 0, $pos);
        $key = substr($dotKey, $pos + 1);

        if (mb_strlen($namespace) > self::MAX_NAMESPACE || mb_strlen($key) > self::MAX_KEY) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Chave demasiado longa: '%s'. O grupo pode ter até %d caracteres e o nome até %d.",
                $dotKey,
                self::MAX_NAMESPACE,
                self::MAX_KEY
            ));
        }

        return [$namespace, $key];
    }

    /**
     * Operação inversa de parse(): ('ui', 'theme') → 'ui.theme'.
     */
    public static function join(string $namespace, string $key): string
    {
        return $namespace . '.' . $key;
    }

    /**
     * Valida o nome de um contexto ('global', 'user:42', 'tenant:5', ...).
     *
     * @throws InvalidArgumentException
     */
    public static function context(string $context): string
    {
        if ($context === '' || trim($context) !== $context || mb_strlen($context) > self::MAX_CONTEXT) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Contexto inválido: '%s'. Usa um texto sem espaços nas pontas "
                . "e com até %d caracteres, por exemplo 'global' ou 'user:42'.",
                $context,
                self::MAX_CONTEXT
            ));
        }

        return $context;
    }
}
