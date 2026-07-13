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
        protected int $cacheTtl = 300,
        protected string $cachePrefix = 'settings:',
        protected ?string $cacheDriver = null,
    ) {
    }

    // ── Context factories ─────────────────────────────────────────────────────

    public static function globalContext(): string
    {
        return config('settings.contexts.default', 'global');
    }

    public static function userContext(mixed $user = null): string
    {
        $prefix = config('settings.contexts.user', 'user');

        $id = match (true) {
            is_int($user) => $user,
            is_object($user) => $user->getKey(),
            default => Auth::id()
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
        string $dotKey,
        mixed $value,
        string $context = 'global',
        ?string $cast = null,
        array $options = []
    ): Setting {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        $cast ??= $this->inferCast($value);

        $setting = Setting::updateOrCreate(
            [
                'namespace' => $namespace,
                'key' => $key,
                'context' => $context,
            ],
            [
                'value' => $value,
                'cast' => $cast,
                'is_locked' => $options['is_locked'] ?? false,
                'is_inheritable' => $options['is_inheritable'] ?? false,
                'visibility' => $options['visibility'] ?? 'editable',
                'metadata' => $options['metadata'] ?? null,
                'updated_by' => Auth::id(),
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
            'key' => $key,
            'context' => $context,
        ])->update(['is_locked' => true, 'updated_by' => Auth::id()]);

        $this->bustCache($namespace, $key, $context);
    }

    public function forget(string $dotKey, string $context = 'global'): void
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        Setting::where([
            'namespace' => $namespace,
            'key' => $key,
            'context' => $context,
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
     * "Undefined variable" (ou key/context, dependendo da ordem).
     *
     * IMPORTANTE (2): o cache guarda um ARRAY primitivo, não a instância
     * Eloquent Setting directamente — evita __PHP_Incomplete_Class quando a
     * estrutura da classe muda entre versões do pacote com cache persistente
     * (Redis/Memcached) activo.
     *
     * IMPORTANTE (3): o valor lido do cache é validado com is_array() antes
     * de usar. Isto protege contra qualquer entrada corrompida ou de formato
     * antigo que ainda esteja no driver de cache — por exemplo, se um worker
     * PHP-FPM diferente gravou uma entrada com o código anterior antes do
     * cache:clear propagar a todos os processos, ou se o driver de cache é
     * partilhado entre deploys sem invalidação atómica. Em vez de deixar o
     * forceFill() rebentar com TypeError, tratamos qualquer valor que não
     * seja array (incluindo objectos, __PHP_Incomplete_Class, ou lixo) como
     * cache miss: ignoramos e lemos a BD directamente, sem passar pelo
     * Cache::remember() para essa chamada — o que também corrige a entrada
     * na próxima escrita de cache.
     *
     * IMPORTANTE (4): "não existe na BD" é cacheado com um marcador sentinela
     * (self::CACHE_MISS_MARKER), nunca com null bruto. Alguns drivers de
     * cache — versões antigas da extensão Memcached, em particular — tratam
     * put($key, null, $ttl) de forma inconsistente: nalgumas o valor é
     * gravado normalmente, noutras a chamada é silenciosamente tratada como
     * um forget(). Isso faria o "cache negativo" (evitar bater na BD outra
     * vez para uma setting que sabemos não existir) nunca funcionar nesses
     * drivers especificamente, sem qualquer erro visível — só mais queries
     * do que o esperado. Guardar uma string sentinela em vez de null
     * funciona de forma idêntica em file, redis, memcached e database.
     */
    private const CACHE_MISS_MARKER = '__settings_miss__';

    protected function remember(string $namespace, string $key, string $context): ?Setting
    {
        $fetch = function () use ($namespace, $key, $context) {
            $setting = Setting::where([
                'namespace' => $namespace,
                'key' => $key,
                'context' => $context,
            ])->first();

            return $setting?->getAttributes();
        };

        $cacheKey = $this->cacheKey($namespace, $key, $context);
        $cached = $this->store()->get($cacheKey);

        if ($cached === self::CACHE_MISS_MARKER) {
            return null;
        }

        // Cache miss normal, ou entrada corrompida/de formato antigo —
        // nos dois casos lemos a BD e regravamos o cache com o formato certo.
        if ($cached !== null && !is_array($cached)) {
            $this->store()->forget($cacheKey);
            $cached = null;
        }

        if ($cached === null) {
            $cached = $fetch();
            $this->store()->put($cacheKey, $cached ?? self::CACHE_MISS_MARKER, $this->cacheTtl);
        }

        if ($cached === null) {
            return null;
        }

        return (new Setting())->forceFill($cached)->syncOriginal();
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
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => config('settings.default_cast', 'string'),
        };
    }
}