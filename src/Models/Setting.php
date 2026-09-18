<?php

namespace Gsebastiao\LaravelSettings\Models;

use Gsebastiao\LaravelSettings\Casts\SettingValueCast;
use Gsebastiao\LaravelSettings\Concerns\HasCompositeKey;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Gsebastiao\LaravelSettings\Support\SettingKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Uma setting guardada na base de dados.
 *
 * Normalmente não precisas de usar este model directamente — Settings::get()
 * e Settings::set() tratam de tudo. É útil, por exemplo, para listar settings
 * num painel de administração:
 *
 *   Setting::forNamespace('ui')->global()->get();
 *   Setting::whereDotKey('ui.theme')->forContext('user:42')->first();
 *
 * A chave primária é composta por (namespace, key, context). Alterações
 * feitas através deste model (save, delete, restore...) limpam a cache
 * automaticamente.
 *
 * @property string $namespace grupo, ex.: 'ui'
 * @property string $key nome, ex.: 'theme'
 * @property string $context 'global', 'user:42', 'tenant:5', ...
 * @property mixed $value valor já convertido para o tipo indicado em `cast`
 * @property string $cast string|int|float|bool|json|array|date
 * @property bool $is_locked contextos mais específicos não podem ter valor próprio
 * @property bool $is_inheritable é copiada por SettingsInheritance::forUser()
 * @property string $visibility hidden|readonly|editable
 * @property array<string, mixed>|null $metadata dados livres (ex.: label e tipo de input)
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Setting extends Model
{
    use HasCompositeKey;
    use SoftDeletes;

    public const VISIBILITIES = ['hidden', 'readonly', 'editable'];

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    /** @var array<int, string> */
    protected array $compositeKey = ['namespace', 'key', 'context'];

    protected $fillable = [
        'namespace',
        'key',
        'context',
        'value',
        'cast',
        'is_locked',
        'is_inheritable',
        'visibility',
        'metadata',
        'updated_by',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_inheritable' => 'boolean',
        'metadata' => 'array',
        'value' => SettingValueCast::class,
    ];

    protected static function booted(): void
    {
        // Qualquer gravação ou remoção limpa a cache do(s) contexto(s) afectado(s).
        $forgetCache = static function (Setting $setting): void {
            if (! app()->bound(SettingsService::class)) {
                return;
            }

            $contexts = array_filter(
                [$setting->getOriginal('context'), $setting->getAttribute('context')],
                fn (mixed $context): bool => is_string($context) && $context !== ''
            );

            if ($contexts !== []) {
                app(SettingsService::class)->forgetCachedContexts($contexts);
            }
        };

        static::saved($forgetCache);
        static::deleted($forgetCache);
    }

    /**
     * O nome da tabela vem de config('settings.table').
     */
    public function getTable(): string
    {
        return config('settings.table', 'settings');
    }

    /**
     * Permissões (tabela settings_managers) desta setting.
     *
     * Não é uma relação Eloquent — usa sempre $setting->managers()->get().
     * Devolve uma query vazia se a tabela settings_managers não existir.
     */
    public function managers(): Builder
    {
        if (! SettingManager::tableExists()) {
            return SettingManager::query()->whereRaw('1 = 0');
        }

        return SettingManager::query()
            ->where('namespace', $this->namespace)
            ->where('key', $this->key)
            ->where('context', $this->context);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForContext(Builder $query, string $context): Builder
    {
        return $query->where('context', $context);
    }

    public function scopeForNamespace(Builder $query, string $namespace): Builder
    {
        return $query->where('namespace', $namespace);
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('context', SettingsService::globalContext());
    }

    /**
     * Só settings marcadas com is_inheritable = true.
     */
    public function scopeInheritable(Builder $query): Builder
    {
        return $query->where('is_inheritable', true);
    }

    /**
     * Setting::whereDotKey('ui.theme') → namespace 'ui' e key 'theme'.
     */
    public function scopeWhereDotKey(Builder $query, string $dotKey): Builder
    {
        [$namespace, $key] = SettingKey::parse($dotKey);

        return $query->where('namespace', $namespace)->where('key', $key);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** 'ui.theme' */
    public function getDotKey(): string
    {
        return SettingKey::join($this->namespace, $this->key);
    }

    /** 'ui.theme@user:42' */
    public function getUniqueKey(): string
    {
        return "{$this->namespace}.{$this->key}@{$this->context}";
    }

    public function isOverridable(): bool
    {
        return ! $this->is_locked;
    }

    public function isHidden(): bool
    {
        return $this->visibility === 'hidden';
    }

    public function isReadonly(): bool
    {
        return $this->visibility === 'readonly';
    }

    public function isEditable(): bool
    {
        return $this->visibility === 'editable';
    }
}
