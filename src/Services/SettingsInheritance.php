<?php

namespace Gsebastiao\LaravelSettings\Services;

use Gsebastiao\LaravelSettings\Casts\SettingValueCast;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Support\SettingKey;
use Illuminate\Support\Collection;

/**
 * Copia settings marcadas com is_inheritable para o contexto de um utilizador.
 *
 * NOTA: não precisas disto para um utilizador novo "ver" os valores globais —
 * a leitura já faz fallback para o global. A cópia serve para o utilizador
 * ficar com os valores do momento do registo: mudanças posteriores no global
 * (ou no tenant) deixam de o afectar.
 *
 *   $inheritance = app(SettingsInheritance::class);
 *
 *   $inheritance->forUser($user);                     // global → user
 *   $inheritance->forUser($user, tenantId: 5);        // tenant:5 (ou global) → user
 *   $inheritance->forUser($user, namespaces: ['ui']); // só o grupo 'ui'
 *   $inheritance->preview($user);                     // mostra o que seria copiado
 *   $inheritance->resetUser($user);                   // apaga as do user e copia de novo
 *
 * Só são copiadas settings com is_inheritable = true, e nunca por cima de um
 * valor que o utilizador já tenha.
 */
class SettingsInheritance
{
    public function __construct(protected SettingsService $settings)
    {
    }

    /**
     * @param  mixed  $user  model, ID ou null (utilizador autenticado)
     * @param  mixed  $tenantId  copia primeiro do tenant e depois do global
     * @param  array<int, string>  $namespaces  só estes grupos (vazio = todos)
     * @param  string|null  $from  contexto de origem personalizado (em vez do tenant)
     * @return array{copied: int, skipped: int, namespaces: array<int, string>}
     */
    public function forUser(mixed $user, mixed $tenantId = null, array $namespaces = [], ?string $from = null): array
    {
        $plan = $this->plan($user, $tenantId, $namespaces, $from);
        $copied = 0;

        if ($plan['copy'] !== []) {
            $copied = (new Setting())->getConnection()->transaction(
                fn (): int => $this->copy($plan['userContext'], $plan['copy'])
            );

            $this->settings->forgetCachedContexts($plan['userContext']);
        }

        return [
            'copied' => $copied,
            'skipped' => count($plan['skip']),
            'namespaces' => array_values(array_unique(array_map(
                fn (array $item): string => $item['row']['namespace'],
                array_values($plan['copy'])
            ))),
        ];
    }

    /**
     * O que forUser() faria, sem alterar nada.
     *
     * @return Collection<string, array{namespace: string, key: string, value: mixed, cast: string, source: string, action: string}>
     */
    public function preview(mixed $user, mixed $tenantId = null, array $namespaces = [], ?string $from = null): Collection
    {
        $plan = $this->plan($user, $tenantId, $namespaces, $from);

        return (new Collection($plan['candidates']))->map(fn (array $row, string $dot): array => [
            'namespace' => $row['namespace'],
            'key' => $row['key'],
            'value' => SettingValueCast::decode($row['value'], $row['cast']),
            'cast' => $row['cast'],
            'source' => $row['context'],
            'action' => isset($plan['skip'][$dot]) ? 'skip' : 'copy',
        ]);
    }

    /**
     * Apaga as settings do utilizador (dos grupos indicados) e copia de novo.
     * Tudo numa transacção: se algo falhar, nada é apagado.
     *
     * @return array{copied: int, skipped: int, namespaces: array<int, string>}
     */
    public function resetUser(mixed $user, mixed $tenantId = null, array $namespaces = [], ?string $from = null): array
    {
        $userContext = SettingsService::userContext($user);

        $report = (new Setting())->getConnection()->transaction(function () use ($user, $tenantId, $namespaces, $from, $userContext): array {
            $query = Setting::withTrashed()->where('context', $userContext);

            if ($namespaces !== []) {
                $query->whereIn('namespace', $namespaces);
            }

            $query->forceDelete();

            return $this->forUser($user, $tenantId, $namespaces, $from);
        });

        $this->settings->forgetCachedContexts($userContext);

        return $report;
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * @return array{userContext: string, candidates: array<string, array<string, mixed>>, copy: array<string, array{row: array<string, mixed>, trashed: bool}>, skip: array<string, array<string, mixed>>}
     */
    protected function plan(mixed $user, mixed $tenantId, array $namespaces, ?string $from): array
    {
        $userContext = SettingsService::userContext($user);
        $candidates = $this->loadCandidates($this->resolveSources($tenantId, $from), $namespaces);
        $existing = $this->loadExisting($userContext, $namespaces);
        $copy = [];
        $skip = [];

        foreach ($candidates as $dot => $row) {
            if (($existing[$dot] ?? null) === false) {
                $skip[$dot] = $row; // o utilizador já tem valor próprio: não é sobrescrito
            } else {
                $copy[$dot] = ['row' => $row, 'trashed' => ($existing[$dot] ?? null) === true];
            }
        }

        return ['userContext' => $userContext, 'candidates' => $candidates, 'copy' => $copy, 'skip' => $skip];
    }

    /**
     * @param  array<string, array{row: array<string, mixed>, trashed: bool}>  $items
     */
    protected function copy(string $userContext, array $items): int
    {
        $model = new Setting();
        $connection = $model->getConnection();
        $now = $model->freshTimestamp();
        $updatedBy = SettingsService::updatedBy();
        $copied = 0;
        $inserts = [];

        foreach ($items as $item) {
            $row = $item['row'];
            $values = [
                'value' => $row['value'], // texto tal como está na BD, sem conversões
                'cast' => $row['cast'],
                'is_locked' => false, // o utilizador pode sempre alterar as suas
                'is_inheritable' => filter_var($row['is_inheritable'], FILTER_VALIDATE_BOOLEAN),
                'visibility' => $row['visibility'],
                'metadata' => $row['metadata'],
                'updated_by' => $updatedBy,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ];

            if ($item['trashed']) {
                // Existiu e foi apagada com forget(): reaproveitamos a linha
                // (inserir outra rebentava com chave duplicada).
                $copied += $connection->table($model->getTable())
                    ->where('namespace', $row['namespace'])
                    ->where('key', $row['key'])
                    ->where('context', $userContext)
                    ->update($values);
            } else {
                $inserts[] = ['namespace' => $row['namespace'], 'key' => $row['key'], 'context' => $userContext] + $values;
            }
        }

        foreach (array_chunk($inserts, 50) as $chunk) {
            // insertOrIgnore: se outro pedido copiou o mesmo ao mesmo tempo, não rebenta.
            $copied += $connection->table($model->getTable())->insertOrIgnore($chunk);
        }

        return $copied;
    }

    /**
     * Contextos de origem, por prioridade: [tenant ou $from, global].
     *
     * @return array<int, string>
     */
    protected function resolveSources(mixed $tenantId, ?string $from): array
    {
        $first = $from !== null
            ? SettingKey::context($from)
            : ($tenantId !== null ? SettingsService::tenantContext($tenantId) : null);

        return array_values(array_unique(array_filter([$first, SettingsService::globalContext()])));
    }

    /**
     * Para cada chave, a linha do contexto de origem com mais prioridade — e só
     * se essa linha for herdável. (Se o tenant tiver um valor próprio NÃO
     * herdável, o valor do global não é copiado por cima dele.)
     *
     * @param  array<int, string>  $sources
     * @return array<string, array<string, mixed>>
     */
    protected function loadCandidates(array $sources, array $namespaces): array
    {
        $query = Setting::query()->whereIn('context', $sources);

        if ($namespaces !== []) {
            $query->whereIn('namespace', $namespaces);
        }

        $winners = [];

        foreach ($query->toBase()->get() as $row) {
            $row = (array) $row;
            $dot = SettingKey::join($row['namespace'], $row['key']);
            $priority = array_search($row['context'], $sources, true);

            if (! isset($winners[$dot]) || $priority < $winners[$dot]['priority']) {
                $winners[$dot] = ['priority' => $priority, 'row' => $row];
            }
        }

        $candidates = [];

        foreach ($winners as $dot => $winner) {
            if (filter_var($winner['row']['is_inheritable'], FILTER_VALIDATE_BOOLEAN)) {
                $candidates[$dot] = $winner['row'];
            }
        }

        ksort($candidates, SORT_STRING);

        return $candidates;
    }

    /**
     * Settings que o utilizador já tem: 'grupo.nome' => está apagada (soft delete)?
     *
     * @return array<string, bool>
     */
    protected function loadExisting(string $userContext, array $namespaces): array
    {
        $query = Setting::withTrashed()->where('context', $userContext);

        if ($namespaces !== []) {
            $query->whereIn('namespace', $namespaces);
        }

        $existing = [];

        foreach ($query->toBase()->get(['namespace', 'key', 'deleted_at']) as $row) {
            $existing[SettingKey::join($row->namespace, $row->key)] = $row->deleted_at !== null;
        }

        return $existing;
    }
}
