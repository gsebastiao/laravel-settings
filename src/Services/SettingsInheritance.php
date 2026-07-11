<?php

namespace Gsebastiao\LaravelSettings\Services;

use Gsebastiao\LaravelSettings\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SettingsInheritance — copia settings de um contexto fonte para o contexto
 * de um utilizador recém-cadastrado.
 *
 * ── Filtro is_inheritable ─────────────────────────────────────────────────────
 *
 * Só settings marcadas explicitamente com is_inheritable=true são copiadas.
 * Isto evita que configurações internas (versão da app, credenciais de mail,
 * chaves de API) sejam copiadas para cada utilizador. Por defeito, uma nova
 * setting tem is_inheritable=false — precisas de a activar deliberadamente:
 *
 *   Settings::set('ui.theme', 'dark', options: ['is_inheritable' => true]);
 *
 * O valor de is_inheritable e de visibility são copiados juntos com o valor,
 * mantendo o comportamento consistente no contexto do utilizador.
 *
 * ── Modo normal (sem multitenancy) ───────────────────────────────────────────
 *
 *   app(SettingsInheritance::class)->forUser($user);
 *
 *   Copia as settings inheritable de 'global' para 'user:42'.
 *   O utilizador pode alterar as suas depois livremente.
 *
 * ── Modo SaaS multitenant ────────────────────────────────────────────────────
 *
 *   app(SettingsInheritance::class)->forUser($user, tenantId: 5);
 *
 *   Hierarquia de cópia: tenant:5 → global (tenant sobrepõe global).
 *   Só considera settings inheritable em qualquer dos dois contextos.
 *
 * ── Copiar só um namespace ───────────────────────────────────────────────────
 *
 *   app(SettingsInheritance::class)->forUser($user, namespaces: ['ui', 'mail']);
 *
 * ── Copiar de um contexto personalizado ──────────────────────────────────────
 *
 *   app(SettingsInheritance::class)->forUser($user, from: 'tenant:5');
 *
 * ── Verificar o que seria copiado (dry run) ──────────────────────────────────
 *
 *   $preview = app(SettingsInheritance::class)->preview($user, tenantId: 5);
 */
class SettingsInheritance
{
    public function __construct(
        protected SettingsService $settings
    ) {}

    /**
     * Copia settings para o contexto do utilizador.
     *
     * Apenas settings com is_inheritable=true são consideradas — ver o filtro
     * em loadCandidates(). Uma setting is_inheritable=false nunca aparece
     * aqui, mesmo que exista no(s) contexto(s) fonte.
     *
     * @param  Model       $user        O utilizador recém-cadastrado
     * @param  int|null    $tenantId    Se fornecido, activa o modo multitenant
     * @param  array       $namespaces  Filtrar por namespaces; vazio = todos
     * @param  string|null $from        Contexto fonte manual (sobrepõe tenantId)
     * @return array{copied: int, skipped: int, namespaces: array}  Relatório
     */
    public function forUser(
        Model   $user,
        ?int    $tenantId   = null,
        array   $namespaces = [],
        ?string $from       = null,
    ): array {
        $userContext = SettingsService::userContext($user);

        // Resolver as fontes por ordem de prioridade (primeiro ganha)
        $sources = $this->resolveSources($tenantId, $from);

        // Carregar as settings de todas as fontes de uma só vez
        $candidates = $this->loadCandidates($sources, $namespaces);

        // Eliminar settings já existentes no contexto do user (não sobrescreve)
        $existing = $this->loadExisting($userContext, $namespaces);

        $toInsert  = [];
        $skipped   = 0;

        foreach ($candidates as $nsKey => $setting) {
            if ($existing->has($nsKey)) {
                $skipped++;
                continue;
            }

            $toInsert[] = [
                'namespace'      => $setting->namespace,
                'key'            => $setting->key,
                'context'        => $userContext,
                'value'          => $setting->getRawOriginal('value'), // texto puro, sem cast
                'cast'           => $setting->cast,
                'is_locked'      => false, // user pode sempre editar as suas
                'is_inheritable' => $setting->is_inheritable, // propaga a definição
                'visibility'     => $setting->visibility,     // propaga a visibilidade
                'metadata'       => $setting->getRawOriginal('metadata'),
                'updated_by'     => Auth::id(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }

        // Insert em batch — muito mais eficiente do que N queries updateOrCreate
        if (! empty($toInsert)) {
            DB::table(config('settings.table', 'settings'))->insert($toInsert);
        }

        // Invalidar o cache para o contexto do user
        foreach ($toInsert as $row) {
            $this->settings->bustCache($row['namespace'], $row['key'], $userContext);
        }

        return [
            'copied'     => count($toInsert),
            'skipped'    => $skipped,
            'namespaces' => collect($toInsert)->pluck('namespace')->unique()->values()->all(),
        ];
    }

    /**
     * Pré-visualiza o que seria copiado sem efectuar alterações (dry run).
     * Útil para mostrar ao admin o que o utilizador vai herdar.
     *
     * @return Collection<string, array{namespace, key, value, cast, source}>
     */
    public function preview(
        Model   $user,
        ?int    $tenantId   = null,
        array   $namespaces = [],
        ?string $from       = null,
    ): Collection {
        $userContext = SettingsService::userContext($user);
        $sources     = $this->resolveSources($tenantId, $from);
        $candidates  = $this->loadCandidates($sources, $namespaces);
        $existing    = $this->loadExisting($userContext, $namespaces);

        return $candidates->map(function (Setting $setting, string $nsKey) use ($existing, $sources) {
            return [
                'namespace' => $setting->namespace,
                'key'       => $setting->key,
                'value'     => $setting->value,       // valor já castado
                'cast'      => $setting->cast,
                'source'    => $setting->context,     // 'global' ou 'tenant:5'
                'action'    => $existing->has($nsKey) ? 'skip' : 'copy',
            ];
        });
    }

    /**
     * Repõe as settings de um utilizador ao estado herdado (reset).
     * Apaga todos os registos 'user:X' e copia novamente das fontes.
     */
    public function resetUser(
        Model   $user,
        ?int    $tenantId   = null,
        array   $namespaces = [],
    ): array {
        $userContext = SettingsService::userContext($user);

        $query = Setting::where('context', $userContext);

        if (! empty($namespaces)) {
            $query->whereIn('namespace', $namespaces);
        }

        $query->forceDelete(); // ignora SoftDeletes para reset limpo

        return $this->forUser($user, $tenantId: $tenantId, namespaces: $namespaces);
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    /**
     * Resolve a lista de contextos fonte por ordem de prioridade.
     * O primeiro contexto que tiver uma setting para dado namespace.key ganha.
     *
     * Modo normal:    ['global']
     * Modo tenant:    ['tenant:5', 'global']
     * Modo manual:    ['custom_context', 'global']
     */
    protected function resolveSources(?int $tenantId, ?string $from): array
    {
        if ($from !== null) {
            return [$from, SettingsService::globalContext()];
        }

        if ($tenantId !== null) {
            return [SettingsService::tenantContext($tenantId), SettingsService::globalContext()];
        }

        return [SettingsService::globalContext()];
    }

    /**
     * Carrega as settings de todos os contextos fonte e resolve por prioridade.
     * Retorna uma Collection keyed por 'namespace.key', o valor mais prioritário ganha.
     *
     * IMPORTANTE: só considera settings com is_inheritable=true. Isto é o que
     * impede que 'general.version', 'mail.smtp_password' ou qualquer setting
     * interna seja copiada para o utilizador — nem entra na lista de candidatos.
     *
     * @return Collection<string, Setting>
     */
    protected function loadCandidates(array $sources, array $namespaces): Collection
    {
        $query = Setting::whereIn('context', $sources)->inheritable();

        if (! empty($namespaces)) {
            $query->whereIn('namespace', $namespaces);
        }

        // Agrupa por 'namespace.key' e escolhe o contexto mais prioritário
        return $query->get()
            ->groupBy(fn (Setting $s) => "{$s->namespace}.{$s->key}")
            ->map(function (Collection $group) use ($sources) {
                // Ordena pelo índice do sources array — menor índice = maior prioridade
                return $group->sortBy(
                    fn (Setting $s) => array_search($s->context, $sources)
                )->first();
            });
    }

    /**
     * Carrega as settings já existentes no contexto do user.
     *
     * @return Collection<string, bool>  keyed por 'namespace.key'
     */
    protected function loadExisting(string $userContext, array $namespaces): Collection
    {
        $query = Setting::withTrashed()->where('context', $userContext);

        if (! empty($namespaces)) {
            $query->whereIn('namespace', $namespaces);
        }

        return $query->get()
            ->keyBy(fn (Setting $s) => "{$s->namespace}.{$s->key}")
            ->map(fn () => true);
    }
}
