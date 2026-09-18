<?php

namespace Gsebastiao\LaravelSettings\Exceptions;

use RuntimeException;

/**
 * Lançada por lock()/unlock() quando a setting não existe no contexto indicado.
 */
class SettingNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $dotKey,
        public readonly string $context,
    ) {
        parent::__construct(sprintf(
            "[gsebastiao/laravel-settings] Não existe a setting '%s' no contexto '%s'. "
            . "Cria-a primeiro com Settings::set('%s', ...), ou grava-a já bloqueada com "
            . "Settings::set('%s', \$valor, options: ['is_locked' => true]).",
            $dotKey,
            $context,
            $dotKey,
            $dotKey
        ));
    }
}
