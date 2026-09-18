<?php

namespace Gsebastiao\LaravelSettings\Services;

use BackedEnum;
use Closure;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Models\SettingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Stringable;

/**
 * Decide o que um utilizador pode fazer com uma setting:
 * 'hidden' (não vê), 'readonly' (vê) ou 'editable' (vê e altera).
 *
 *   1. Parte da `visibility` da setting.
 *   2. Se existir uma permissão (SettingManager::grant) para o utilizador, ou
 *      para um dos seus roles, NESSE contexto, a permissão ganha. Permissão
 *      directa ao utilizador ganha à do role.
 *   3. Se a setting estiver bloqueada (lock) num contexto mais geral, fica no
 *      máximo 'readonly' — não pode ser alterada aqui.
 *
 *   $access = app(SettingsAccessControl::class);
 *
 *   $access->visibilityFor('billing.plan', context: 'tenant:5', user: $user); // 'readonly'
 *   $access->canView('billing.plan', context: 'tenant:5', user: $user);       // true
 *   $access->canEdit('billing.plan', context: 'tenant:5', user: $user);       // false
 */
class SettingsAccessControl
{
    protected static ?Closure $rolesResolver = null;

    protected ?bool $pivotAvailable = null;

    public function __construct(protected SettingsService $settings)
    {
    }

    /**
     * Define como obter os roles de um utilizador. Chama no boot() do
     * AppServiceProvider (funciona com php artisan config:cache):
     *
     *   SettingsAccessControl::resolveRolesUsing(fn ($user) => $user->roles->pluck('slug'));
     *
     * Por defeito usa $user->getRoleNames() (spatie/laravel-permission), se existir.
     */
    public static function resolveRolesUsing(?callable $resolver): void
    {
        static::$rolesResolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /**
     * Visibilidade final ('hidden', 'readonly' ou 'editable'). Uma setting que
     * não existe na cadeia de contextos é 'hidden'.
     */
    public function visibilityFor(string $dotKey, string|array|null $context = null, ?Model $user = null): string
    {
        $chain = $this->settings->contextChain($context);
        $setting = $this->settings->find($dotKey, $chain);

        return $setting === null ? 'hidden' : $this->resolve($setting, $user, $chain[0]);
    }

    /**
     * O mesmo que visibilityFor(), para uma Setting já carregada.
     * $context é o contexto onde a setting vai ser mostrada (por defeito, o dela).
     */
    public function resolve(Setting $setting, ?Model $user = null, ?string $context = null): string
    {
        $context ??= $setting->context;
        $grants = $user !== null && $this->pivotAvailable() ? $this->loadGrants($user, [$context]) : [];

        return $this->decide($setting, $context, $grants);
    }

    public function canView(string $dotKey, string|array|null $context = null, ?Model $user = null): bool
    {
        return $this->visibilityFor($dotKey, $context, $user) !== 'hidden';
    }

    public function canEdit(string $dotKey, string|array|null $context = null, ?Model $user = null): bool
    {
        return $this->visibilityFor($dotKey, $context, $user) === 'editable';
    }

    /**
     * Mantém só as settings que o utilizador pode ver. Faz uma única query às
     * permissões, seja qual for o tamanho da lista.
     *
     * @param  Collection<int, Setting>  $settings
     * @return Collection<int, Setting>
     */
    public function filterVisible(Collection $settings, ?Model $user = null, ?string $context = null): Collection
    {
        $grants = [];

        if ($user !== null && $settings->isNotEmpty() && $this->pivotAvailable()) {
            $contexts = $settings->map(fn (Setting $setting): string => $context ?? $setting->context)
                ->unique()
                ->values()
                ->all();

            $grants = $this->loadGrants($user, $contexts);
        }

        return $settings
            ->filter(fn (Setting $setting): bool => $this->decide($setting, $context ?? $setting->context, $grants) !== 'hidden')
            ->values();
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>>  $grants
     */
    protected function decide(Setting $setting, string $context, array $grants): string
    {
        $visibility = in_array($setting->visibility, Setting::VISIBILITIES, true) ? $setting->visibility : 'hidden';
        $visibility = $this->grantFor($grants, $setting->namespace, $setting->key, $context) ?? $visibility;

        if ($visibility === 'editable' && $setting->is_locked && $setting->context !== $context) {
            return 'readonly';
        }

        return $visibility;
    }

    /**
     * Todas as permissões do utilizador e dos seus roles nestes contextos,
     * numa só query.
     *
     * @param  array<int, string>  $contexts
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    protected function loadGrants(Model $user, array $contexts): array
    {
        $userId = (string) $user->getKey();
        $roles = $this->rolesFor($user);

        $rows = SettingManager::query()
            ->whereIn('context', $contexts)
            ->where(function ($query) use ($userId, $roles): void {
                $query->where(fn ($query) => $query->where('manager_type', 'user')->where('manager_id', $userId));

                if ($roles !== []) {
                    $query->orWhere(fn ($query) => $query->where('manager_type', 'role')->whereIn('manager_id', $roles));
                }
            })
            ->toBase()
            ->get(['namespace', 'key', 'context', 'manager_type', 'visibility']);

        $grants = [];

        foreach ($rows as $row) {
            $grants[$row->namespace . "\0" . $row->key . "\0" . $row->context][] = [$row->manager_type, $row->visibility];
        }

        return $grants;
    }

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>>  $grants
     */
    protected function grantFor(array $grants, string $namespace, string $key, string $context): ?string
    {
        $matches = $grants[$namespace . "\0" . $key . "\0" . $context] ?? [];

        foreach ($matches as [$type, $visibility]) {
            if ($type === 'user') {
                return $visibility; // permissão directa ao utilizador tem prioridade
            }
        }

        if ($matches === []) {
            return null;
        }

        // Vários roles com regras diferentes: vale a mais permissiva.
        foreach ($matches as [, $visibility]) {
            if ($visibility === 'editable') {
                return 'editable';
            }
        }

        return 'readonly';
    }

    /**
     * @return array<int, string>
     */
    protected function rolesFor(Model $user): array
    {
        $resolver = static::$rolesResolver ?? $this->resolverFromConfig();

        $roles = match (true) {
            $resolver !== null => $resolver($user),
            method_exists($user, 'getRoleNames') => $user->getRoleNames(),
            default => [],
        };

        $names = [];

        // Aceita array, Collection (com ou sem ->all()), enums ou um só nome.
        foreach (Collection::wrap($roles) as $role) {
            if ($role instanceof BackedEnum) {
                $role = $role->value;
            }

            if (is_int($role) || is_string($role) || $role instanceof Stringable) {
                $names[] = (string) $role;
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));
    }

    protected function resolverFromConfig(): ?callable
    {
        $resolver = config('settings.resolve_roles');

        if ($resolver === null || $resolver === '') {
            return null;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver); // classe com __invoke($user)
        } elseif (is_array($resolver) && isset($resolver[0], $resolver[1]) && is_string($resolver[0]) && ! is_callable($resolver)) {
            $resolver = [app($resolver[0]), $resolver[1]]; // [Classe::class, 'metodo']
        }

        if (! is_callable($resolver)) {
            throw new InvalidArgumentException(
                "[gsebastiao/laravel-settings] config('settings.resolve_roles') tem de ser uma função, "
                . "uma classe com __invoke(\$user) ou [Classe::class, 'metodo']."
            );
        }

        return $resolver;
    }

    protected function pivotAvailable(): bool
    {
        return $this->pivotAvailable ??= SettingManager::tableExists();
    }
}
