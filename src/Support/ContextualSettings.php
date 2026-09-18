<?php

namespace Gsebastiao\LaravelSettings\Support;

use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Models\Setting;
use Illuminate\Support\Collection;

/**
 * As mesmas operações do Settings::, mas presas a um contexto.
 *
 *   $prefs = Settings::forUser($user);    // user:42 → global
 *   $prefs->get('ui.theme', 'light');
 *   $prefs->set('ui.theme', 'dark');      // grava em user:42
 *
 *   Settings::forUser($user, tenant: 5)   // user:42 → tenant:5 → global
 *   Settings::forTenant(5)                // tenant:5 → global
 *   Settings::forContext('shop:3')        // shop:3 → global
 *
 * As leituras procuram na cadeia inteira; as escritas vão sempre para o
 * primeiro contexto (o mais específico).
 */
final class ContextualSettings
{
    /**
     * @param  array<int, string>  $chain
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly array $chain,
    ) {
    }

    public function get(string $dotKey, mixed $default = null): mixed
    {
        return $this->settings->get($dotKey, $default, $this->chain);
    }

    public function find(string $dotKey): ?Setting
    {
        return $this->settings->find($dotKey, $this->chain);
    }

    public function has(string $dotKey): bool
    {
        return $this->settings->has($dotKey, $this->chain);
    }

    public function all(string $namespace): Collection
    {
        return $this->settings->all($namespace, $this->chain);
    }

    public function set(string $dotKey, mixed $value, ?string $cast = null, array $options = []): Setting
    {
        return $this->settings->set($dotKey, $value, $this->chain, $cast, $options);
    }

    public function setMany(array $values): void
    {
        $this->settings->setMany($values, $this->chain);
    }

    public function forget(string $dotKey): bool
    {
        return $this->settings->forget($dotKey, $this->chain);
    }

    public function lock(string $dotKey): void
    {
        $this->settings->lock($dotKey, $this->chain);
    }

    public function unlock(string $dotKey): void
    {
        $this->settings->unlock($dotKey, $this->chain);
    }

    public function isLocked(string $dotKey): bool
    {
        return $this->settings->isLocked($dotKey, $this->chain);
    }

    /** O contexto onde as escritas são feitas, ex.: 'user:42'. */
    public function context(): string
    {
        return $this->chain[0];
    }

    /**
     * A cadeia de leitura, ex.: ['user:42', 'tenant:5', 'global'].
     *
     * @return array<int, string>
     */
    public function chain(): array
    {
        return $this->chain;
    }
}
