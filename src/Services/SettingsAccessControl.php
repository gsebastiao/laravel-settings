<?php

namespace Gsebastiao\LaravelSettings\Services;

use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Models\SettingManager;
use Illuminate\Database\Eloquent\Model;

/**
 * SettingsAccessControl — resolve a visibilidade final de uma setting para
 * um utilizador concreto.
 *
 * ── Lógica de resolução ──────────────────────────────────────────────────────
 *
 *   1. A tabela settings_managers existe e tem registo para este user/role?
 *        SIM → usa o visibility do pivot (readonly ou editable) — tem mais peso
 *        NÃO → passo 2
 *   2. Usa o visibility da própria setting (hidden|readonly|editable)
 *
 * Numa app simples que nunca correu a migration de settings_managers, o passo 1
 * é sempre "não" (verificado uma vez via SettingManager::tableExists() e
 * ignorado depois) — o custo extra é zero.
 *
 * ── Uso básico ────────────────────────────────────────────────────────────────
 *
 *   $access = app(SettingsAccessControl::class);
 *
 *   // Visibilidade final para o utilizador autenticado
 *   $access->visibilityFor('billing.plan', context: 'tenant:5', user: auth()->user());
 *   // → 'readonly' | 'editable' | 'hidden'
 *
 *   // Atalhos
 *   $access->canView('billing.plan', context: 'tenant:5', user: $user);   // bool
 *   $access->canEdit('billing.plan', context: 'tenant:5', user: $user);   // bool
 *
 *   // Filtrar uma lista de settings para o que o utilizador pode ver
 *   $visible = $access->filterVisible($settings, user: $user);
 */
class SettingsAccessControl
{
    /**
     * Resolve a visibilidade final de uma setting para um utilizador.
     *
     * @param  string     $dotKey  'namespace.key'
     * @param  string     $context Contexto da setting
     * @param  Model|null $user    Utilizador a verificar; null = sem utilizador autenticado
     */
    public function visibilityFor(string $dotKey, string $context, ?Model $user = null): string
    {
        [$namespace, $key] = $this->parseDotKey($dotKey);

        $setting = Setting::where(compact('namespace', 'key', 'context'))->first();

        if ($setting === null) {
            return 'hidden'; // setting inexistente é sempre invisível
        }

        return $this->resolve($setting, $user);
    }

    /**
     * Resolve a visibilidade a partir de uma instância Setting já carregada
     * (evita uma query extra quando já tens o objecto em mãos).
     */
    public function resolve(Setting $setting, ?Model $user = null): string
    {
        // Cenário simples: tabela pivot nunca foi migrada → usa só o padrão
        if (! SettingManager::tableExists() || $user === null) {
            return $setting->visibility;
        }

        $pivotVisibility = $this->lookupPivot($setting, $user);

        // O pivot, quando existe, sobrepõe sempre o visibility da setting —
        // mesmo que a setting seja 'hidden', um gestor explícito tem acesso.
        return $pivotVisibility ?? $setting->visibility;
    }

    public function canView(string $dotKey, string $context, ?Model $user = null): bool
    {
        return $this->visibilityFor($dotKey, $context, $user) !== 'hidden';
    }

    public function canEdit(string $dotKey, string $context, ?Model $user = null): bool
    {
        return $this->visibilityFor($dotKey, $context, $user) === 'editable';
    }

    /**
     * Filtra uma coleção de Settings, mantendo apenas as que o utilizador
     * pode ver (visibility resolvido != 'hidden'). Útil para construir uma
     * página de configurações que só mostra o que é relevante para o user.
     *
     * @param  \Illuminate\Support\Collection<int, Setting>  $settings
     * @return \Illuminate\Support\Collection<int, Setting>
     */
    public function filterVisible(\Illuminate\Support\Collection $settings, ?Model $user = null): \Illuminate\Support\Collection
    {
        return $settings->filter(
            fn (Setting $setting) => $this->resolve($setting, $user) !== 'hidden'
        )->values();
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    /**
     * Procura no pivot por um registo do utilizador directamente, ou de
     * qualquer um dos roles que ele tem. Utilizador tem prioridade sobre role
     * quando ambos existem (mais específico ganha).
     */
    protected function lookupPivot(Setting $setting, Model $user): ?string
    {
        $base = SettingManager::where('namespace', $setting->namespace)
            ->where('key', $setting->key)
            ->where('context', $setting->context);

        // 1. Match directo por user_id — mais específico, verificado primeiro
        $userMatch = (clone $base)
            ->where('manager_type', 'user')
            ->where('manager_id', (string) $user->getKey())
            ->first();

        if ($userMatch !== null) {
            return $userMatch->visibility;
        }

        // 2. Match por qualquer um dos roles do utilizador.
        // Nota: a ordenação por prioridade (editable > readonly) é feita em
        // PHP, não em SQL, porque FIELD() só existe em MySQL — isto mantém
        // o pacote portável entre SQLite, MySQL e PostgreSQL.
        $roles = $this->resolveUserRoles($user);

        if (empty($roles)) {
            return null;
        }

        $roleMatches = (clone $base)
            ->where('manager_type', 'role')
            ->whereIn('manager_id', $roles)
            ->get();

        if ($roleMatches->isEmpty()) {
            return null;
        }

        // Se o utilizador tiver vários roles com regras diferentes para a
        // mesma setting, 'editable' ganha sobre 'readonly' (mais permissivo).
        return $roleMatches->contains('visibility', 'editable')
            ? 'editable'
            : 'readonly';
    }

    /**
     * Resolve os roles do utilizador. Usa config('settings.resolve_roles') se
     * definido, senão tenta getRoleNames() (compatível com spatie/laravel-permission).
     * Retorna [] silenciosamente se nada disso existir — nunca quebra a app.
     *
     * @return array<int, string>
     */
    protected function resolveUserRoles(Model $user): array
    {
        $resolver = config('settings.resolve_roles');

        if (is_callable($resolver)) {
            return (array) $resolver($user);
        }

        if (method_exists($user, 'getRoleNames')) {
            return $user->getRoleNames()->all();
        }

        return [];
    }

    protected function parseDotKey(string $dotKey): array
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
