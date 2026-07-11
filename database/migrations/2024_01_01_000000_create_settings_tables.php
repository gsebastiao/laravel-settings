<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration única com as duas tabelas do pacote.
 *
 * ── settings (núcleo, sempre necessária) ────────────────────────────────────
 *
 * Guarda os valores propriamente ditos, com chave composta
 * (namespace + key + context) e hierarquia global → tenant → user.
 *
 * ── settings_managers (controlo de acesso, totalmente opcional) ────────────
 *
 * Pivot para dar a um utilizador ou role acesso individual a uma setting
 * específica, sobrepondo o 'visibility' padrão dessa setting. Corre sempre
 * junto com 'settings' nesta migration única, mas:
 *
 *   - Se nunca a usares (Settings::get()/set() chega para o teu caso), a
 *     tabela fica vazia e não tem qualquer custo — nenhuma query a toca.
 *   - Se preferires nem tê-la na base de dados, comenta o bloco correspondente
 *     abaixo antes de correr `php artisan migrate`, ou apaga a tabela depois:
 *       php artisan tinker
 *       Schema::dropIfExists(config('settings.managers_table'));
 *   - O SettingManager::tableExists() verifica a existência da tabela em
 *     runtime, por isso removê-la depois de migrada não quebra nada — o
 *     SettingsAccessControl simplesmente ignora o pivot e usa o 'visibility'
 *     da própria setting.
 *
 * Nomes de tabela configuráveis via config('settings.table') e
 * config('settings.managers_table') — ou SETTINGS_TABLE / SETTINGS_MANAGERS_TABLE
 * no .env.
 */
return new class extends Migration
{
    private string $settingsTable;
    private string $managersTable;

    public function __construct()
    {
        $this->settingsTable = config('settings.table', 'settings');
        $this->managersTable = config('settings.managers_table', 'settings_managers');
    }

    public function up(): void
    {
        // ── Tabela 1: settings ───────────────────────────────────────────────
        Schema::create($this->settingsTable, function (Blueprint $table) {
            // Chave composta (namespace + key + context)
            $table->string('namespace', 64);
            $table->string('key', 128);
            $table->string('context', 128)->default('global');

            // Valor e tipo
            $table->text('value')->nullable();
            $table->string('cast', 16)->default('string');

            // Comportamento
            $table->boolean('is_locked')->default(false);

            // Controla se esta setting é copiada para o contexto do utilizador
            // quando ele é cadastrado (ver SettingsInheritance::forUser()).
            // Default false: nada é herdado até decidires explicitamente.
            $table->boolean('is_inheritable')->default(false);

            // Visibilidade PADRÃO para quem não tem registo na tabela opcional
            // settings_managers. Se essa tabela estiver vazia ou não tiver
            // registo para o utilizador/role, esta é a regra que vale.
            //   hidden   → não aparece para o utilizador
            //   readonly → aparece mas não pode ser editada
            //   editable → aparece e pode ser editada
            // Default 'editable': mantém o comportamento simples sem controlo
            // de acesso — quem não usa settings_managers nunca precisa disto.
            $table->enum('visibility', ['hidden', 'readonly', 'editable'])
                  ->default('editable');

            // Metadados de apresentação (input type, options, classes CSS)
            $table->json('metadata')->nullable();

            // Auditoria
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            // Constraints
            $table->primary(['namespace', 'key', 'context']);
            $table->index('context');
            $table->index('namespace');

            // FK opcional — não assume que a tabela users existe com este nome.
            // Para activar, publica esta migration e adiciona manualmente:
            // $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── Tabela 2: settings_managers (opcional — ver docblock acima) ──────
        Schema::create($this->managersTable, function (Blueprint $table) {
            // Chave que aponta para a setting (FK lógica, não física).
            // Não é uma FK física porque 'settings' usa PK composta e o Laravel
            // lida mal com FKs compostas em alguns drivers (SQLite, sobretudo).
            // A integridade é garantida pela aplicação (ver SettingManager::grant()).
            $table->string('namespace', 64);
            $table->string('key', 128);
            $table->string('context', 128);

            // Quem é o gestor
            $table->enum('manager_type', ['role', 'user']);
            $table->string('manager_id', 64); // nome do role OU id do user como string

            // Visibilidade atribuída a este gestor — sobrepõe o 'visibility'
            // da tabela settings quando existe um match. Só readonly|editable
            // faz sentido aqui — um "gestor" nunca é para quem a setting está
            // hidden (isso é tratado pela ausência de registo).
            $table->enum('visibility', ['readonly', 'editable'])->default('readonly');

            // Auditoria
            $table->timestamps();
            $table->softDeletes(); // revogar (delete) sem perder o histórico

            // Chave primária composta
            $table->primary(
                ['namespace', 'key', 'context', 'manager_type', 'manager_id'],
                'settings_managers_pk'
            );

            // Índices para as queries mais comuns
            // "Que settings este user/role pode gerir?" → filtra por manager
            $table->index(['manager_type', 'manager_id'], 'settings_managers_manager_idx');
            // "Quem gere esta setting?" → filtra pela chave da setting
            $table->index(['namespace', 'key', 'context'], 'settings_managers_setting_idx');
        });
    }

    public function down(): void
    {
        // Ordem inversa: derruba primeiro quem depende logicamente da outra
        Schema::dropIfExists($this->managersTable);
        Schema::dropIfExists($this->settingsTable);
    }
};
