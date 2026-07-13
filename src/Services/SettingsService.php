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
            [
                'namespace' => $namespace,
                'key'       => $key,
                'context'   => $context,
            ],
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

        Setting::where([
            'namespace' => $namespace,
            'key'       => $key,
            'context'   => $context,
        ])->update(['is_locked' => true, 'updated_by' => Auth::id()]);

        $this->bustCache($namespace, $key, $context);
    }

    public function forget(string $dotKey, string $context = 'global'): void
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        Setting::where([
            'namespace' => $namespace,
            'key'       => $key,
            'context'   => $context,
        ])->delete();

        $this->bustCache($namespace, $key, $context);
    }

    public function forgetContext(string $context): void
    {
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

    /**
     * IMPORTANTE: usa array literal ['namespace' => $namespace, ...] em vez de
     * compact('namespace', 'key', 'context') dentro da arrow function.
     *
     * compact() lê variáveis pelo nome em runtime (via get_defined_vars()),
     * não por referência sintáctica. O motor PHP decide o que uma arrow
     * function captura analisando estaticamente quais variáveis aparecem
     * como identificadores no corpo — compact('namespace') não conta como
     * uso de $namespace aos olhos dessa análise, por isso a variável nunca
     * é capturada e o compact() executa sem ela definida, lançando
     * "Undefined variable $namespace" (ou key/context, dependendo da ordem).
     *
     * Isto é mais visível em PHP 8.2+ com arrow functions (fn); closures
     * tradicionais (function() use (...)) nunca tiveram este problema porque
     * exigem `use` explícito.
     */
    protected function remember(string $namespace, string $key, string $context): ?Setting
    {
        return $this->store()->remember(
            $this->cacheKey($namespace, $key, $context),
            $this->cacheTtl,
            fn () => Setting::where([
                'namespace' => $namespace,
                'key'       => $key,
                'context'   => $context,
            ])->first()
        );
    }

    protected function bustCache(string $namespace, string $key, string $context): void
    {
        $this->store()->forget($this->cacheKey($namespace, $key, $context));
    }

    protected function cacheKey(string $namespace, string $key, string $context): string
    {
        return $this->cachePrefix . "{$namespace}.{$key}@{$context}";
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    protected function buildContextChain(string $context): array
    {
        $global = static::globalContext();

        if ($context === $global) {
            return [$global];
        }

        return [$context, $global];
    }

    /**
     * Divide 'namespace.key' cortando no ÚLTIMO ponto, não no primeiro.
     *
     * Isto permite namespaces com múltiplos níveis:
     *   'format.date_time.date'  → namespace='format.date_time', key='date'
     *   'format.currency.symbol' → namespace='format.currency',  key='symbol'
     *   'general.name'           → namespace='general',          key='name'
     *
     * Cortar no primeiro ponto (comportamento antigo) produzia
     * namespace='format', key='date_time.date' — que nunca coincide com um
     * registo gravado como namespace='format.date_time', key='date'.
     */
    protected function parseDotKey(string $dotKey): array
    {
        $pos = strrpos($dotKey, '.');

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
