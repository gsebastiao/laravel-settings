<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas do pacote gsebastiao/laravel-settings.
 *
 *   settings          → os valores (sempre usada)
 *   settings_managers → permissões por utilizador/role (opcional: se nunca
 *                       chamares SettingManager::grant(), fica vazia)
 *
 * Os nomes vêm de config('settings.table') e config('settings.managers_table').
 *
 * Se publicares esta migration para a personalizar, NÃO mudes o nome do
 * ficheiro: é assim que o Laravel sabe que é a mesma e não a corre duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('settings.table', 'settings'), function (Blueprint $table) {
            // Identificação: grupo + nome + contexto ('global', 'user:42', ...)
            $table->string('namespace', 64);
            $table->string('key', 128);
            $table->string('context', 128)->default('global');

            // O valor é guardado como texto; `cast` diz como o converter na leitura
            $table->text('value')->nullable();
            $table->string('cast', 16)->default('string');

            // Opções
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_inheritable')->default(false);
            $table->enum('visibility', ['hidden', 'readonly', 'editable'])->default('editable');
            $table->json('metadata')->nullable();

            // Auditoria (updated_by só é preenchido com IDs numéricos)
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();

            $table->primary(['namespace', 'key', 'context']);
            $table->index('context');
            $table->index('namespace');
        });

        Schema::create(config('settings.managers_table', 'settings_managers'), function (Blueprint $table) {
            // A que setting se refere (sem chave estrangeira física: a
            // integridade é garantida pelo pacote)
            $table->string('namespace', 64);
            $table->string('key', 128);
            $table->string('context', 128);

            // A quem é dada a permissão
            $table->enum('manager_type', ['role', 'user']);
            $table->string('manager_id', 64); // nome do role ou ID do utilizador

            // O que pode fazer: ver ou ver e editar
            $table->enum('visibility', ['readonly', 'editable'])->default('readonly');

            $table->timestamps();
            $table->softDeletes(); // revogar sem perder o histórico

            $table->primary(['namespace', 'key', 'context', 'manager_type', 'manager_id'], 'settings_managers_pk');
            $table->index(['manager_type', 'manager_id'], 'settings_managers_manager_idx');
            $table->index(['namespace', 'key', 'context'], 'settings_managers_setting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('settings.managers_table', 'settings_managers'));
        Schema::dropIfExists(config('settings.table', 'settings'));
    }
};
