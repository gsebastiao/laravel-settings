<?php

namespace Gsebastiao\LaravelSettings\Services;

use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class SettingsService implements SettingsRepository
{
    public function __construct(
        protected int     $cacheTtl    = 300,
        protected string  $cachePrefix = 'settings:',
        protected ?string $cacheDriver = null,
    ) {}

    // ── Context factories ─────────────────────────────────────────────────────

    public static function globalContext(): string
    {
        return config('settings.contexts.default', 'global');
    }

    /**
     * Contexto do utilizador autenticado ou de um ID/Model específico.
     *
     * @param  int|object|null  $user
     */
    public static function userContext(mixed $user = null): string
    {
        $prefix = config('settings.contexts.user', 'user');

        $id = match (true) {
            is_int($user)    => $user,
            is_object($user) => $user->getKey(),
            default          => Auth::id()
                ?? throw new \RuntimeException(
                    '[gsebastiao/laravel-settings] Nenhum utilizador autenticado para userContext(). '
                    . 'Passa o user explicitamente: SettingsService::userContext($user)'
                ),
        };

        return $prefix . ':' . $id;
    }

    /**
     * Contexto de tenant/organização.
     */
    public static function tenantContext(int $tenantId): string
    {
        $prefix = config('settings.contexts.tenant', 'tenant');

        return $prefix . ':' . $tenantId;
    }

    // ── Leitura ───────────────────────────────────────────────────────────────

    public function get(string $dotKey, mixed $default = null, string $context = 'global'): mixed
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        foreach ($this->buildContextChain($context) as $ctx) {
            $setting = $this->remember($namespace, $key, $ctx);

            if ($setting !== null) {
                return $setting->value;
            }
        }

        return $default;
    }

    public function all(string $namespace, string $context = 'global'): Collection
    {
        $contexts = $this->buildContextChain($context);

        $rows = Setting::forNamespace($namespace)
            ->whereIn('context', $contexts)
            ->get()
            ->groupBy('key');

        return $rows->map(function (Collection $group) use ($contexts) {
            foreach ($contexts as $ctx) {
                $setting = $group->firstWhere('context', $ctx);
                if ($setting) {
                    return $setting->value;
                }
            }
            return null;
        });
    }

    public function has(string $dotKey, string $context = 'global'): bool
    {
        return $this->get($dotKey, null, $context) !== null;
    }

    // ── Escrita ───────────────────────────────────────────────────────────────

    public function set(
        string  $dotKey,
        mixed   $value,
        string  $context = 'global',
        ?string $cast    = null,
        array   $options = []
    ): Setting {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        $cast ??= $this->inferCast($value);

        $setting = Setting::updateOrCreate(
            compact('namespace', 'key', 'context'),
            [
                'value'          => $value,
                'cast'           => $cast,
                'is_locked'      => $options['is_locked']      ?? false,
                'is_inheritable' => $options['is_inheritable'] ?? false,
                'visibility'     => $options['visibility']     ?? 'editable',
                'metadata'       => $options['metadata']       ?? null,
                'updated_by'     => Auth::id(),
            ]
        );

        $this->bustCache($namespace, $key, $context);

        return $setting;
    }

    public function lock(string $dotKey, string $context = 'global'): void
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        Setting::where(compact('namespace', 'key', 'context'))
            ->update(['is_locked' => true, 'updated_by' => Auth::id()]);

        $this->bustCache($namespace, $key, $context);
    }

    public function forget(string $dotKey, string $context = 'global'): void
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        Setting::where(compact('namespace', 'key', 'context'))->delete();

        $this->bustCache($namespace, $key, $context);
    }

    public function forgetContext(string $context): void
    {
        // Obtém todas as chaves antes de apagar, para invalidar o cache individualmente
        $keys = Setting::where('context', $context)->get(['namespace', 'key']);

        Setting::where('context', $context)->delete();

        foreach ($keys as $row) {
            $this->bustCache($row->namespace, $row->key, $context);
        }
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    protected function store(): \Illuminate\Contracts\Cache\Repository
    {
        return $this->cacheDriver
            ? Cache::store($this->cacheDriver)
            : Cache::store();
    }

    protected function remember(string $namespace, string $key, string $context): ?Setting
    {
        return $this->store()->remember(
            $this->cacheKey($namespace, $key, $context),
            $this->cacheTtl,
            fn () => Setting::where(compact('namespace', 'key', 'context'))->first()
        );
    }

    public function bustCache(string $namespace, string $key, string $context): void
    {
        $this->store()->forget($this->cacheKey($namespace, $key, $context));
    }

    protected function cacheKey(string $namespace, string $key, string $context): string
    {
        return $this->cachePrefix . "{$namespace}.{$key}@{$context}";
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    /**
     * Cadeia de resolução do mais específico para o mais geral.
     *
     *  'user:42'  → ['user:42', 'global']
     *  'tenant:5' → ['tenant:5', 'global']
     *  'global'   → ['global']
     */
    protected function buildContextChain(string $context): array
    {
        $global = static::globalContext();

        if ($context === $global) {
            return [$global];
        }

        return [$context, $global];
    }

    /**
     * 'namespace.key'     → ['namespace', 'key']
     * 'mail.smtp.host'    → ['mail',      'smtp.host']
     */
    protected function parseDotKey(string $dotKey): array
    {
        $pos = strpos($dotKey, '.');

        if ($pos === false) {
            throw new \InvalidArgumentException(
                "[gsebastiao/laravel-settings] Formato inválido: '{$dotKey}'. Use 'namespace.key'."
            );
        }

        return [substr($dotKey, 0, $pos), substr($dotKey, $pos + 1)];
    }

    protected function inferCast(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => config('settings.default_cast', 'string'),
        };
    }
}
