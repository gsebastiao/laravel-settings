<?php

namespace Gsebastiao\LaravelSettings\Exceptions;

use RuntimeException;

/**
 * Lançada quando tentas gravar um valor num contexto (ex.: 'user:42') para
 * uma setting que um contexto mais geral (ex.: 'global') bloqueou com lock().
 */
class SettingLockedException extends RuntimeException
{
    public function __construct(
        public readonly string $dotKey,
        public readonly string $context,
        public readonly string $lockedIn,
    ) {
        parent::__construct(sprintf(
            "[gsebastiao/laravel-settings] A setting '%s' está bloqueada no contexto '%s' e não pode ser alterada em '%s'. "
            . "Para permitir valores próprios, desbloqueia-a primeiro: Settings::unlock('%s', context: '%s').",
            $dotKey,
            $lockedIn,
            $context,
            $dotKey,
            $lockedIn
        ));
    }
}
