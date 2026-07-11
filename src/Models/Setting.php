<?php

namespace Gsebastiao\LaravelSettings\Models;

use Gsebastiao\LaravelSettings\Casts\SettingValueCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string      $namespace
 * @property string      $key
 * @property string      $context
 * @property mixed       $value           cast automático via SettingValueCast
 * @property string      $cast            string|int|float|bool|json|array|date
 * @property bool        $is_locked
 * @property bool        $is_inheritable  se true, é copiada ao cadastrar um utilizador
 * @property string      $visibility      hidden|readonly|editable (regra padrão)
 * @property array|null  $metadata
 */
class Setting extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $primaryKey = null;

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
        'is_locked'       => 'boolean',
        'is_inheritable'  => 'boolean',
        'metadata'        => 'array',
        'value'           => SettingValueCast::class,
    ];

    /**
     * O nome da tabela é configurável via config('settings.table').
     * Permite usar a tabela 'app_settings' ou qualquer outro nome sem
     * precisar de publicar e editar o Model.
     */
    public function getTable(): string
    {
        return config('settings.table', 'settings');
    }

    // ── Query helper (não é uma relação Eloquent tradicional) ──────────────────

    /**
     * Retorna um query builder para os gestores desta setting específica.
     *
     * NOTA: não é uma relação Eloquent (hasMany/belongsTo) porque 'settings'
     * usa PK composta e 'settings_managers' não tem FK física para ela (ver
     * comentário na migration). Por isso NÃO suporta eager loading com
     * with('managers') — chama sempre explicitamente:
     *
     *   $setting->managers()->get();
     *
     * Retorna uma query vazia (0 resultados) se a tabela settings_managers
     * não existir, em vez de lançar excepção.
     */
    public function managers(): \Illuminate\Database\Eloquent\Builder
    {
        if (! SettingManager::tableExists()) {
            // Query que nunca traz resultados, mas não quebra a chamada
            return SettingManager::whereRaw('1 = 0');
        }

        return SettingManager::where('namespace', $this->namespace)
            ->where('key', $this->key)
            ->where('context', $this->context);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForContext($query, string $context)
    {
        return $query->where('context', $context);
    }

    public function scopeForNamespace($query, string $namespace)
    {
        return $query->where('namespace', $namespace);
    }

    public function scopeGlobal($query)
    {
        return $query->where('context', config('settings.contexts.default', 'global'));
    }

    /**
     * Apenas settings marcadas para herança (is_inheritable = true).
     * Usado por SettingsInheritance::forUser() para filtrar o que copiar.
     */
    public function scopeInheritable($query)
    {
        return $query->where('is_inheritable', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    public function isEditable(): bool
    {
        return $this->visibility === 'editable';
    }
}
