<?php

namespace Gsebastiao\LaravelSettings\Models;

use BackedEnum;
use Gsebastiao\LaravelSettings\Concerns\HasCompositeKey;
use Gsebastiao\LaravelSettings\Support\SettingKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Permissão OPCIONAL: dá a um utilizador ou a um role acesso a uma setting,
 * num contexto, sobrepondo a `visibility` normal dessa setting.
 *
 * A tabela é criada pela migration do pacote, mas enquanto não chamares
 * grant() fica vazia e não custa nada.
 *
 *   // O role 'manager' pode VER billing.plan no tenant 5
 *   SettingManager::grant('billing.plan', context: 'tenant:5', type: 'role', id: 'manager');
 *
 *   // O utilizador 42 pode EDITAR mail.from_name no global
 *   SettingManager::grant('mail.from_name', context: 'global', type: 'user', id: 42, visibility: 'editable');
 *
 *   // Retirar
 *   SettingManager::revoke('billing.plan', context: 'tenant:5', type: 'role', id: 'manager');
 *
 * A permissão vale para o contexto em que foi dada.
 *
 * @property string $namespace
 * @property string $key
 * @property string $context
 * @property string $manager_type 'role' | 'user'
 * @property string $manager_id nome do role ou ID do utilizador
 * @property string $visibility 'readonly' | 'editable'
 */
class SettingManager extends Model
{
    use HasCompositeKey;
    use SoftDeletes;

    public const TYPES = ['role', 'user'];

    public const VISIBILITIES = ['readonly', 'editable'];

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    /** @var array<int, string> */
    protected array $compositeKey = ['namespace', 'key', 'context', 'manager_type', 'manager_id'];

    protected $fillable = [
        'namespace',
        'key',
        'context',
        'manager_type',
        'manager_id',
        'visibility',
    ];

    protected $casts = [
        'manager_id' => 'string', // comparação consistente com o ID do utilizador
    ];

    public function getTable(): string
    {
        return config('settings.managers_table', 'settings_managers');
    }

    /**
     * A tabela existe? (Podes apagá-la se nunca usares permissões.)
     */
    public static function tableExists(): bool
    {
        $model = new static();

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /**
     * Dá (ou actualiza) uma permissão.
     *
     * @param  string  $type  'role' ou 'user'
     * @param  int|string|Model|BackedEnum  $id  nome do role, ID do utilizador ou o próprio model
     * @param  string  $visibility  'readonly' (ver) ou 'editable' (ver e editar)
     */
    public static function grant(
        string $dotKey,
        string $context,
        string $type,
        int|string|Model|BackedEnum $id,
        string $visibility = 'readonly',
    ): static {
        $attributes = static::identify($dotKey, $context, $type, $id);

        if (! in_array($visibility, static::VISIBILITIES, true)) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Visibilidade inválida numa permissão: '%s'. Usa 'readonly' ou 'editable'.",
                $visibility
            ));
        }

        static::ensureTableExists();

        // withTrashed(): uma permissão revogada volta a ser usada em vez de
        // tentar inserir uma linha nova com a mesma chave (que rebentava).
        $grant = static::withTrashed()->where($attributes)->first();

        if ($grant === null) {
            $grant = new static($attributes);
        } elseif ($grant->trashed()) {
            $grant->setAttribute($grant->getDeletedAtColumn(), null);
        }

        $grant->visibility = $visibility;
        $grant->save();

        return $grant;
    }

    /**
     * Retira uma permissão. Devolve false se não existia.
     */
    public static function revoke(string $dotKey, string $context, string $type, int|string|Model|BackedEnum $id): bool
    {
        $attributes = static::identify($dotKey, $context, $type, $id);

        if (! static::tableExists()) {
            return false;
        }

        $grant = static::query()->where($attributes)->first();

        return $grant !== null && $grant->delete() !== false;
    }

    /**
     * Dá a mesma permissão a vários utilizadores/roles.
     *
     * @param  array<int, array{type: string, id: int|string}>  $managers
     */
    public static function grantMany(string $dotKey, string $context, array $managers, string $visibility = 'readonly'): void
    {
        foreach ($managers as $index => $manager) {
            if (! is_array($manager) || ! isset($manager['type'], $manager['id'])) {
                throw new InvalidArgumentException(sprintf(
                    "[gsebastiao/laravel-settings] grantMany(): o item %s tem de ter 'type' e 'id', "
                    . "por exemplo ['type' => 'role', 'id' => 'admin'].",
                    $index
                ));
            }

            static::grant($dotKey, $context, $manager['type'], $manager['id'], $visibility);
        }
    }

    /**
     * @return array<string, string>
     */
    protected static function identify(string $dotKey, string $context, string $type, mixed $id): array
    {
        [$namespace, $key] = SettingKey::parse($dotKey);

        if (! in_array($type, static::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Tipo de permissão inválido: '%s'. Usa 'user' ou 'role'.",
                $type
            ));
        }

        if ($id instanceof Model) {
            // Para roles guardamos o NOME (ex.: spatie/laravel-permission), não o id.
            $id = $type === 'role' && $id->getAttribute('name') !== null ? $id->getAttribute('name') : $id->getKey();
        }

        if ($id instanceof BackedEnum) {
            $id = $id->value;
        }

        $managerId = is_int($id) || is_string($id) ? (string) $id : '';

        if ($managerId === '' || mb_strlen($managerId) > 64) {
            throw new InvalidArgumentException(
                '[gsebastiao/laravel-settings] Identificador inválido numa permissão: usa o ID do utilizador '
                . 'ou o nome do role (até 64 caracteres).'
            );
        }

        return [
            'namespace' => $namespace,
            'key' => $key,
            'context' => SettingKey::context($context),
            'manager_type' => $type,
            'manager_id' => $managerId,
        ];
    }

    protected static function ensureTableExists(): void
    {
        if (! static::tableExists()) {
            throw new RuntimeException(sprintf(
                "[gsebastiao/laravel-settings] A tabela '%s' não existe. Corre: php artisan migrate",
                (new static())->getTable()
            ));
        }
    }
}
