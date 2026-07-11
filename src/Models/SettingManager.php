<?php

namespace Gsebastiao\LaravelSettings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * SettingManager — pivot OPCIONAL que atribui a um utilizador ou role a
 * permissão de ver/editar uma setting específica, sobrepondo o 'visibility'
 * padrão dessa setting.
 *
 * Esta tabela é criada automaticamente junto com 'settings' (mesma migration
 * ..._create_settings_tables.php), mas o SEU USO é opcional: enquanto nunca
 * chamares grant(), fica vazia e sem custo. Se a removeste manualmente da
 * base de dados, usa tableExists() para verificar antes de qualquer query —
 * o SettingsAccessControl já faz isso automaticamente, nunca vais precisar de
 * te preocupar com isto numa app simples.
 *
 * ── Uso básico ────────────────────────────────────────────────────────────────
 *
 *   // Dar a um role acesso de leitura a uma setting normalmente escondida
 *   SettingManager::grant('billing.plan', context: 'tenant:5',
 *       type: 'role', id: 'manager', visibility: 'readonly');
 *
 *   // Dar a um utilizador específico acesso de edição
 *   SettingManager::grant('mail.from_name', context: 'global',
 *       type: 'user', id: 42, visibility: 'editable');
 *
 *   // Revogar
 *   SettingManager::revoke('billing.plan', context: 'tenant:5',
 *       type: 'role', id: 'manager');
 *
 *   // Verificar se a tabela existe (para apps que nunca a migraram)
 *   SettingManager::tableExists(); // bool
 *
 * @property string $namespace
 * @property string $key
 * @property string $context
 * @property string $manager_type   'role' | 'user'
 * @property string $manager_id
 * @property string $visibility     'readonly' | 'editable'
 */
class SettingManager extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'namespace',
        'key',
        'context',
        'manager_type',
        'manager_id',
        'visibility',
    ];

    protected $casts = [
        'manager_id' => 'string', // garante comparação consistente com user->id
    ];

    public function getTable(): string
    {
        return config('settings.managers_table', 'settings_managers');
    }

    // ── Verificação de existência da tabela (cenário simples vs avançado) ──────

    /**
     * Verifica se a tabela pivot existe na base de dados.
     *
     * NOTA: não é cacheado em variável estática de processo. Numa aplicação
     * real o resultado raramente muda depois do boot, mas em suites de
     * testes (Testbench) a base de dados é recriada entre testes — uma
     * cache estática aqui fixaria o resultado do primeiro teste para todos
     * os seguintes, criando falsos positivos/negativos. O custo de um
     * hasTable() é desprezável comparado com esse risco.
     *
     * Se precisares de reduzir chamadas repetidas em produção, cacheia o
     * resultado ao nível da aplicação (ex: Cache::rememberForever()) fora
     * deste pacote, onde tens controlo sobre a invalidação.
     */
    public static function tableExists(): bool
    {
        /** @var SchemaBuilder $schema */
        $schema = Schema::connection((new static())->getConnectionName());

        return $schema->hasTable(config('settings.managers_table', 'settings_managers'));
    }

    // ── API de alto nível: grant / revoke ───────────────────────────────────────

    /**
     * Concede a um utilizador ou role acesso a uma setting específica.
     *
     * @param  string  $dotKey     'namespace.key'
     * @param  string  $context    Contexto da setting ('global', 'tenant:5', ...)
     * @param  string  $type       'role' | 'user'
     * @param  int|string $id      Nome do role ou ID do utilizador
     * @param  string  $visibility 'readonly' | 'editable'
     */
    public static function grant(
        string     $dotKey,
        string     $context,
        string     $type,
        int|string $id,
        string     $visibility = 'readonly',
    ): self {
        if (! static::tableExists()) {
            throw new \RuntimeException(
                '[gsebastiao/laravel-settings] A tabela settings_managers não existe. '
                . 'Corre: php artisan vendor:publish --tag=settings-migrations '
                . 'e depois php artisan migrate.'
            );
        }

        [$namespace, $key] = static::parseDotKey($dotKey);

        return static::updateOrCreate(
            [
                'namespace'    => $namespace,
                'key'          => $key,
                'context'      => $context,
                'manager_type' => $type,
                'manager_id'   => (string) $id,
            ],
            ['visibility' => $visibility]
        );
    }

    /**
     * Revoga o acesso previamente concedido (soft delete).
     */
    public static function revoke(
        string     $dotKey,
        string     $context,
        string     $type,
        int|string $id,
    ): void {
        if (! static::tableExists()) {
            return; // nada a revogar se a tabela nem existe
        }

        [$namespace, $key] = static::parseDotKey($dotKey);

        static::where([
            'namespace'    => $namespace,
            'key'          => $key,
            'context'      => $context,
            'manager_type' => $type,
            'manager_id'   => (string) $id,
        ])->delete();
    }

    /**
     * Concede acesso a vários roles/users de uma vez.
     *
     * @param  array<int, array{type: string, id: int|string}>  $managers
     */
    public static function grantMany(
        string $dotKey,
        string $context,
        array  $managers,
        string $visibility = 'readonly',
    ): void {
        foreach ($managers as $manager) {
            static::grant($dotKey, $context, $manager['type'], $manager['id'], $visibility);
        }
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    protected static function parseDotKey(string $dotKey): array
    {
        $pos = strpos($dotKey, '.');

        if ($pos === false) {
            throw new \InvalidArgumentException(
                "[gsebastiao/laravel-settings] Formato inválido: '{$dotKey}'. Use 'namespace.key'."
            );
        }

        return [substr($dotKey, 0, $pos), substr($dotKey, $pos + 1)];
    }
}
