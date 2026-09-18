<?php

namespace Gsebastiao\LaravelSettings\Services;

use BackedEnum;
use Closure;
use Gsebastiao\LaravelSettings\Casts\SettingValueCast;
use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Exceptions\SettingLockedException;
use Gsebastiao\LaravelSettings\Exceptions\SettingNotFoundException;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Support\ContextualSettings;
use Gsebastiao\LaravelSettings\Support\SettingKey;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Implementação principal: lê e grava settings na base de dados, com cache.
 *
 * ── Como um valor é encontrado ───────────────────────────────────────────────
 *
 * Cada leitura percorre uma cadeia de contextos, do mais específico para o
 * mais geral, que termina sempre no contexto global:
 *
 *   Settings::get('ui.theme')                        → global
 *   Settings::get('ui.theme', context: 'user:42')    → user:42 → global
 *   Settings::forUser($user, tenant: 5)->get(...)    → user:42 → tenant:5 → global
 *
 * Vale o contexto mais específico que tiver valor — excepto quando um
 * contexto mais geral bloqueou a setting (lock): aí vale o valor bloqueado.
 *
 * ── Cache ────────────────────────────────────────────────────────────────────
 *
 * As settings são carregadas por contexto (uma só query para todos os
 * contextos da cadeia) e guardadas na cache do Laravel. Durante um pedido
 * ficam também em memória, por isso chamar setting() dez vezes numa view
 * custa uma única leitura. Qualquer gravação feita pelo pacote ou pelo model
 * Setting limpa a cache do contexto afectado.
 */
class SettingsService implements SettingsRepository
{
    /** Opções aceites em set(..., options: [...]) e os valores de uma setting nova. */
    public const DEFAULT_OPTIONS = [
        'is_locked' => false,
        'is_inheritable' => false,
        'visibility' => 'editable',
        'metadata' => null,
    ];

    /** Muda sempre que o formato guardado na cache mudar. */
    protected const CACHE_FORMAT = 2;

    /**
     * Settings já carregadas neste pedido: contexto => ['grupo.nome' => linha da BD].
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array $loaded = [];

    protected ?int $generation = null;

    public function __construct(
        protected ?int $cacheTtl = 300,
        protected string $cachePrefix = 'settings:',
        protected ?string $cacheDriver = null,
        protected bool $cacheEnabled = true,
    ) {
    }

    // ── Contextos ────────────────────────────────────────────────────────────

    /** 'global' (configurável em settings.contexts.default). */
    public static function globalContext(): string
    {
        return (string) config('settings.contexts.default', 'global');
    }

    /**
     * 'user:42'. Aceita um model, um ID (número ou texto, ex.: UUID) ou nada
     * (usa o utilizador autenticado).
     */
    public static function userContext(mixed $user = null): string
    {
        $user ??= static::authenticatedUserId() ?? throw new RuntimeException(
            '[gsebastiao/laravel-settings] Não há nenhum utilizador autenticado. '
            . 'Indica o utilizador: Settings::forUser($user) ou SettingsService::userContext($user).'
        );

        return config('settings.contexts.user', 'user') . ':' . static::identifier($user);
    }

    /** 'tenant:5'. Aceita um model ou um ID. */
    public static function tenantContext(mixed $tenant): string
    {
        return config('settings.contexts.tenant', 'tenant') . ':' . static::identifier($tenant);
    }

    /**
     * Transforma o parâmetro $context na cadeia de leitura, que termina
     * sempre no contexto global:
     *
     *   null                    → ['global']
     *   'user:42'               → ['user:42', 'global']
     *   ['user:42', 'tenant:5'] → ['user:42', 'tenant:5', 'global']
     *
     * @param  string|array<int, string>|null  $context
     * @return array<int, string>
     */
    public function contextChain(string|array|null $context = null): array
    {
        $global = static::globalContext();
        $chain = [];

        foreach ((array) $context as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(sprintf(
                    "[gsebastiao/laravel-settings] Os contextos têm de ser texto (ex.: 'user:42'); recebido: %s.",
                    get_debug_type($item)
                ));
            }

            SettingKey::context($item);

            if ($item !== $global && ! in_array($item, $chain, true)) {
                $chain[] = $item;
            }
        }

        $chain[] = $global;

        return $chain;
    }

    public function forUser(mixed $user = null, mixed $tenant = null): ContextualSettings
    {
        $contexts = [static::userContext($user)];

        if ($tenant !== null) {
            $contexts[] = static::tenantContext($tenant);
        }

        return $this->forContext($contexts);
    }

    public function forTenant(mixed $tenant): ContextualSettings
    {
        return $this->forContext(static::tenantContext($tenant));
    }

    public function forContext(string|array $context): ContextualSettings
    {
        return new ContextualSettings($this, $this->contextChain($context));
    }

    // ── Leitura ──────────────────────────────────────────────────────────────

    public function get(string $dotKey, mixed $default = null, string|array|null $context = null): mixed
    {
        $row = $this->resolve($dotKey, $context);

        return $row === null ? value($default) : SettingValueCast::decode($row['value'], $row['cast']);
    }

    public function find(string $dotKey, string|array|null $context = null): ?Setting
    {
        $row = $this->resolve($dotKey, $context);

        return $row === null ? null : (new Setting())->newFromBuilder($row);
    }

    public function has(string $dotKey, string|array|null $context = null): bool
    {
        return $this->resolve($dotKey, $context) !== null;
    }

    public function all(string $namespace, string|array|null $context = null): Collection
    {
        $chain = $this->contextChain($context);
        $rows = $this->rowsFor($chain);
        $keys = [];

        foreach ($rows as $contextRows) {
            foreach ($contextRows as $row) {
                if ($row['namespace'] === $namespace) {
                    $keys[(string) $row['key']] = true;
                }
            }
        }

        ksort($keys, SORT_STRING);

        $values = [];

        foreach (array_keys($keys) as $key) {
            $row = $this->pick($rows, $chain, SettingKey::join($namespace, (string) $key));
            $values[$key] = SettingValueCast::decode($row['value'], $row['cast']);
        }

        return new Collection($values);
    }

    public function isLocked(string $dotKey, string|array|null $context = null): bool
    {
        [$namespace, $key] = SettingKey::parse($dotKey);
        $dot = SettingKey::join($namespace, $key);

        foreach ($this->rowsFor($this->contextChain($context)) as $contextRows) {
            if (isset($contextRows[$dot]) && $this->isLockedRow($contextRows[$dot])) {
                return true;
            }
        }

        return false;
    }

    // ── Escrita ──────────────────────────────────────────────────────────────

    public function set(
        string $dotKey,
        mixed $value,
        string|array|null $context = null,
        ?string $cast = null,
        array $options = [],
    ): Setting {
        [$namespace, $key] = SettingKey::parse($dotKey);

        return $this->write(
            $namespace,
            $key,
            $value,
            $this->contextChain($context),
            SettingValueCast::normalize($cast),
            $this->validateOptions($options, $dotKey),
        );
    }

    public function setMany(array $values, string|array|null $context = null): void
    {
        $chain = $this->contextChain($context);
        $items = [];

        // Valida todas as chaves antes de gravar a primeira.
        foreach ($values as $dotKey => $value) {
            $items[] = [...SettingKey::parse((string) $dotKey), $value];
        }

        if ($items === []) {
            return;
        }

        $this->connection()->transaction(function () use ($items, $chain): void {
            foreach ($items as [$namespace, $key, $value]) {
                $this->write($namespace, $key, $value, $chain, null, []);
            }
        });
    }

    public function forget(string $dotKey, string|array|null $context = null): bool
    {
        [$namespace, $key] = SettingKey::parse($dotKey);
        $setting = $this->findRow($namespace, $key, $this->contextChain($context)[0]);

        return $setting !== null && $setting->delete() !== false;
    }

    public function forgetContext(string $context): int
    {
        SettingKey::context($context);

        $removed = Setting::query()->where('context', $context)->delete();
        $this->forgetCachedContexts($context);

        return (int) $removed;
    }

    public function lock(string $dotKey, string|array|null $context = null): void
    {
        $this->toggleLock($dotKey, $context, true);
    }

    public function unlock(string $dotKey, string|array|null $context = null): void
    {
        $this->toggleLock($dotKey, $context, false);
    }

    // ── Cache ────────────────────────────────────────────────────────────────

    public function flushCache(): void
    {
        $this->loaded = [];

        if ($this->cacheEnabled) {
            // Em vez de procurar e apagar cada entrada, mudamos a "geração":
            // todas as chaves antigas deixam de ser usadas.
            $this->generation = null;
            $next = $this->generation() + 1;
            $this->cache()->forever($this->cachePrefix . 'generation', $next);
            $this->generation = $next;
        }
    }

    /**
     * Esquece o que está em memória e em cache para estes contextos. Chamado
     * automaticamente sempre que uma setting é gravada ou apagada.
     *
     * @param  string|array<int, string>  $contexts
     */
    public function forgetCachedContexts(string|array $contexts): void
    {
        $contexts = array_values(array_unique(array_map('strval', (array) $contexts)));

        $forget = function () use ($contexts): void {
            foreach ($contexts as $context) {
                unset($this->loaded[$context]);

                if ($this->cacheEnabled) {
                    $this->cache()->forget($this->cacheKey($context));
                }
            }
        };

        $forget();

        // Dentro de uma transacção, outro pedido pode ler a base de dados antes
        // do COMMIT e voltar a pôr o valor antigo em cache: limpamos outra vez
        // depois do COMMIT.
        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            try {
                $connection->afterCommit($forget);
            } catch (RuntimeException) {
                // Sem gestor de transacções (raro): a limpeza imediata basta.
            }
        }
    }

    // ── Utilizador actual ────────────────────────────────────────────────────

    /** ID do utilizador autenticado, ou null. */
    public static function authenticatedUserId(): int|string|null
    {
        try {
            return app()->bound('auth') ? Auth::id() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Valor para a coluna updated_by (bigint): o ID do utilizador autenticado
     * quando é numérico. IDs em texto (UUID/ULID) ficam null em vez de
     * rebentarem a gravação em MySQL/PostgreSQL.
     */
    public static function updatedBy(): ?int
    {
        $id = static::authenticatedUserId();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    // ── Internos: escrita ────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $chain
     * @param  array<string, mixed>  $options  só as opções pedidas
     */
    protected function write(
        string $namespace,
        string $key,
        mixed $value,
        array $chain,
        ?string $cast,
        array $options,
        bool $retry = true,
    ): Setting {
        $dotKey = SettingKey::join($namespace, $key);

        $this->guardAgainstLocks($namespace, $key, $chain);

        // withTrashed(): uma setting apagada com forget() ainda ocupa a chave
        // na tabela. Até à 1.2.2 isto fazia o set() seguinte rebentar.
        $setting = $this->findRow($namespace, $key, $chain[0], withTrashed: true);
        $isNew = $setting === null || $setting->trashed();

        if ($setting === null) {
            $setting = new Setting(['namespace' => $namespace, 'key' => $key, 'context' => $chain[0]]);
        } elseif ($setting->trashed()) {
            // Volta a existir, como se fosse nova.
            $setting->setAttribute($setting->getDeletedAtColumn(), null);
            $setting->setCreatedAt($setting->freshTimestamp());
        }

        // O tipo tem de ser definido ANTES do valor: é ele que diz como converter.
        $setting->cast = $cast ?? $this->castFor($value, $isNew ? null : $setting->cast);

        try {
            $setting->value = $value;
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Não foi possível gravar '%s': %s",
                $dotKey,
                $e->getMessage()
            ), 0, $e);
        }

        // Numa setting que já existe, só mudam as opções que foram pedidas.
        // (Até à 1.2.2, um set() sem opções apagava lock, visibility, etc.)
        foreach ($isNew ? $options + self::DEFAULT_OPTIONS : $options as $option => $optionValue) {
            $setting->setAttribute($option, $optionValue);
        }

        $setting->updated_by = static::updatedBy();

        try {
            $this->withSavepoint(fn () => $setting->save());
        } catch (UniqueConstraintViolationException $e) {
            // Outro pedido criou a mesma setting entre a nossa leitura e a
            // gravação: tentamos uma segunda vez, agora como actualização.
            if (! $retry || $setting->exists) {
                throw $e;
            }

            return $this->write($namespace, $key, $value, $chain, $cast, $options, retry: false);
        }

        return $setting;
    }

    /**
     * Tipo a usar quando set() é chamado sem $cast.
     */
    protected function castFor(mixed $value, ?string $currentCast): string
    {
        $inferred = SettingValueCast::infer($value);
        $current = $currentCast === null ? null : SettingValueCast::readType($currentCast);

        return match (true) {
            // Um número inteiro numa setting decimal continua decimal.
            $inferred === 'int' && $current === 'float' => 'float',
            // Valor com tipo PHP claro (int, bool, array, data...): esse tipo.
            $inferred !== null => $inferred,
            // Texto (ex.: vindo de um formulário) mantém o tipo que a setting já tinha.
            $current !== null => $current,
            default => SettingValueCast::normalize((string) config('settings.default_cast', 'string')),
        };
    }

    /**
     * @param  array<int, string>  $chain
     */
    protected function guardAgainstLocks(string $namespace, string $key, array $chain): void
    {
        $above = array_slice($chain, 1);

        if ($above === []) {
            return;
        }

        $locked = Setting::query()
            ->where('namespace', $namespace)
            ->where('key', $key)
            ->whereIn('context', $above)
            ->where('is_locked', true)
            ->pluck('context')
            ->all();

        // Se houver vários bloqueios, indicamos o mais geral (é esse que vale).
        foreach (array_reverse($above) as $context) {
            if (in_array($context, $locked, true)) {
                throw new SettingLockedException(SettingKey::join($namespace, $key), $chain[0], $context);
            }
        }
    }

    protected function toggleLock(string $dotKey, string|array|null $context, bool $locked): void
    {
        [$namespace, $key] = SettingKey::parse($dotKey);
        $target = $this->contextChain($context)[0];

        $setting = $this->findRow($namespace, $key, $target)
            ?? throw new SettingNotFoundException($dotKey, $target);

        $setting->is_locked = $locked;
        $setting->updated_by = static::updatedBy();
        $setting->save();
    }

    protected function findRow(string $namespace, string $key, string $context, bool $withTrashed = false): ?Setting
    {
        $query = $withTrashed ? Setting::withTrashed() : Setting::query();

        return $query->where('namespace', $namespace)
            ->where('key', $key)
            ->where('context', $context)
            ->first();
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    protected function validateOptions(array $options, string $dotKey): array
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), array_keys(self::DEFAULT_OPTIONS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Opção desconhecida em '%s': %s. As opções válidas são: %s.",
                $dotKey,
                implode(', ', $unknown),
                implode(', ', array_keys(self::DEFAULT_OPTIONS))
            ));
        }

        foreach (['is_locked', 'is_inheritable'] as $flag) {
            if (array_key_exists($flag, $options)) {
                $options[$flag] = filter_var($options[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new InvalidArgumentException(sprintf(
                        "[gsebastiao/laravel-settings] A opção '%s' de '%s' tem de ser true ou false.",
                        $flag,
                        $dotKey
                    ));
            }
        }

        if (array_key_exists('visibility', $options) && ! in_array($options['visibility'], Setting::VISIBILITIES, true)) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Visibilidade inválida em '%s': %s. Usa 'hidden', 'readonly' ou 'editable'.",
                $dotKey,
                is_scalar($options['visibility']) ? "'" . $options['visibility'] . "'" : get_debug_type($options['visibility'])
            ));
        }

        if (array_key_exists('metadata', $options)) {
            $metadata = $options['metadata'] instanceof Arrayable ? $options['metadata']->toArray() : $options['metadata'];

            if ($metadata !== null && ! is_array($metadata)) {
                throw new InvalidArgumentException(sprintf(
                    "[gsebastiao/laravel-settings] A opção 'metadata' de '%s' tem de ser um array (ou null).",
                    $dotKey
                ));
            }

            $options['metadata'] = $metadata;
        }

        return $options;
    }

    protected function withSavepoint(Closure $callback): mixed
    {
        $connection = $this->connection();

        return $connection->transactionLevel() > 0 ? $connection->transaction($callback) : $callback();
    }

    protected function connection(): Connection
    {
        return (new Setting())->getConnection();
    }

    protected static function identifier(mixed $value): string
    {
        if ($value instanceof Model) {
            $value = $value->getKey();
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ((is_int($value) || is_string($value)) && (string) $value !== '') {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf(
            '[gsebastiao/laravel-settings] Identificador inválido (%s). Usa um ID (número ou texto) ou um model Eloquent já gravado.',
            get_debug_type($value)
        ));
    }

    // ── Internos: leitura ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    protected function resolve(string $dotKey, string|array|null $context): ?array
    {
        [$namespace, $key] = SettingKey::parse($dotKey);
        $chain = $this->contextChain($context);

        return $this->pick($this->rowsFor($chain), $chain, SettingKey::join($namespace, $key));
    }

    /**
     * Escolhe a linha que vale para a cadeia de contextos:
     *   1. se algum contexto bloqueou a setting, vale o bloqueio mais geral;
     *   2. senão, vale o contexto mais específico que tiver valor.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $rows
     * @param  array<int, string>  $chain
     * @return array<string, mixed>|null
     */
    protected function pick(array $rows, array $chain, string $dot): ?array
    {
        foreach (array_reverse($chain) as $context) {
            if (isset($rows[$context][$dot]) && $this->isLockedRow($rows[$context][$dot])) {
                return $rows[$context][$dot];
            }
        }

        foreach ($chain as $context) {
            if (isset($rows[$context][$dot])) {
                return $rows[$context][$dot];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function isLockedRow(array $row): bool
    {
        return filter_var($row['is_locked'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Linhas de cada contexto: memória → cache → base de dados.
     *
     * @param  array<int, string>  $contexts
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function rowsFor(array $contexts): array
    {
        $missing = array_values(array_filter(
            $contexts,
            fn (string $context): bool => ! array_key_exists($context, $this->loaded)
        ));

        if ($missing !== []) {
            $this->loaded = $this->load($missing) + $this->loaded;
        }

        $rows = [];

        foreach ($contexts as $context) {
            $rows[$context] = $this->loaded[$context] ?? [];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $contexts
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function load(array $contexts): array
    {
        $found = [];

        if ($this->cacheEnabled) {
            $keys = [];

            foreach ($contexts as $context) {
                $keys[$this->cacheKey($context)] = $context;
            }

            foreach ($this->cache()->many(array_keys($keys)) as $cacheKey => $payload) {
                // Só aceitamos o nosso formato; qualquer outra coisa conta como "não está em cache".
                if (is_array($payload) && ($payload['format'] ?? null) === self::CACHE_FORMAT && is_array($payload['rows'] ?? null)) {
                    $found[$keys[$cacheKey]] = $payload['rows'];
                }
            }
        }

        $missing = array_values(array_filter(
            $contexts,
            fn (string $context): bool => ! array_key_exists($context, $found)
        ));

        if ($missing === []) {
            return $found;
        }

        $fromDatabase = array_fill_keys($missing, []);

        foreach (Setting::query()->whereIn('context', $missing)->toBase()->get() as $row) {
            $row = (array) $row;

            if (array_key_exists($row['context'], $fromDatabase)) {
                $fromDatabase[$row['context']][SettingKey::join($row['namespace'], $row['key'])] = $row;
            }
        }

        if ($this->cacheEnabled) {
            $payloads = [];

            foreach ($fromDatabase as $context => $rows) {
                // Guardamos só arrays simples — nunca objectos — para a cache
                // continuar legível depois de actualizares o pacote.
                $payloads[$this->cacheKey((string) $context)] = ['format' => self::CACHE_FORMAT, 'rows' => $rows];
            }

            $this->cache()->putMany($payloads, $this->cacheTtl);
        }

        return $found + $fromDatabase;
    }

    protected function cache(): CacheRepository
    {
        return Cache::store($this->cacheDriver);
    }

    protected function cacheKey(string $context): string
    {
        $safe = preg_match('/^[A-Za-z0-9_.:@-]{1,100}$/', $context) === 1 ? $context : 'h:' . sha1($context);

        return $this->cachePrefix . 'v' . $this->generation() . ':' . $safe;
    }

    protected function generation(): int
    {
        return $this->generation ??= (int) ($this->cache()->get($this->cachePrefix . 'generation') ?? 1);
    }
}
