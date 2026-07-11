<?php

namespace Gsebastiao\LaravelSettings\Tests\Unit;

use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Gsebastiao\LaravelSettings\SettingsServiceProvider;
use Orchestra\Testbench\TestCase;

class SettingsServiceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        // A migration única cria as duas tabelas (settings + settings_managers),
        // igual ao que acontece em produção via SettingsServiceProvider::boot().
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    protected function service(): SettingsService
    {
        return app(SettingsService::class);
    }

    // ── Leitura ───────────────────────────────────────────────────────────────

    public function test_retorna_null_se_nao_existir(): void
    {
        $this->assertNull($this->service()->get('general.name'));
    }

    public function test_retorna_default_se_nao_existir(): void
    {
        $this->assertSame('Laravel App', $this->service()->get('general.name', 'Laravel App'));
    }

    public function test_get_valor_global(): void
    {
        Setting::create([
            'namespace' => 'general',
            'key'       => 'name',
            'context'   => 'global',
            'value'     => 'Laravel App',
            'cast'      => 'string',
        ]);

        $this->assertSame('Laravel App', $this->service()->get('general.name'));
    }

    // ── Cast dinâmico ─────────────────────────────────────────────────────────

    public function test_cast_int(): void
    {
        $this->service()->set('ui.font_size', 14, cast: 'int');
        $value = $this->service()->get('ui.font_size');
        $this->assertSame(14, $value);
        $this->assertIsInt($value);
    }

    public function test_cast_bool(): void
    {
        $this->service()->set('ui.dark_mode', true, cast: 'bool');
        $value = $this->service()->get('ui.dark_mode');
        $this->assertTrue($value);
        $this->assertIsBool($value);
    }

    public function test_cast_json(): void
    {
        $this->service()->set('ui.languages', ['pt', 'en', 'es'], cast: 'json');
        $value = $this->service()->get('ui.languages');
        $this->assertSame(['pt', 'en', 'es'], $value);
        $this->assertIsArray($value);
    }

    // ── Hierarquia de contexto ────────────────────────────────────────────────

    public function test_contexto_user_sobrepoe_global(): void
    {
        $svc = $this->service();

        $svc->set('ui.language', 'pt');                        // global
        $svc->set('ui.language', 'en', context: 'user:42');   // user override

        $this->assertSame('pt', $svc->get('ui.language'));
        $this->assertSame('en', $svc->get('ui.language', context: 'user:42'));
    }

    public function test_fallback_para_global_quando_user_nao_tem(): void
    {
        $svc = $this->service();

        $svc->set('ui.language', 'pt'); // apenas global

        // user:99 não tem override → deve retornar o global
        $this->assertSame('pt', $svc->get('ui.language', context: 'user:99'));
    }

    // ── Escrita e remoção ─────────────────────────────────────────────────────

    public function test_set_cria_e_actualiza(): void
    {
        $svc = $this->service();

        $svc->set('general.name', 'Versão 1');
        $this->assertSame('Versão 1', $svc->get('general.name'));

        $svc->set('general.name', 'Versão 2');
        $this->assertSame('Versão 2', $svc->get('general.name'));
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_forget_remove_setting(): void
    {
        $svc = $this->service();

        $svc->set('general.name', 'Laravel App');
        $svc->forget('general.name');

        $this->assertNull($svc->get('general.name'));
    }

    public function test_forget_context_remove_tudo_do_user(): void
    {
        $svc = $this->service();

        $svc->set('ui.theme',    'dark', context: 'user:42');
        $svc->set('ui.language', 'en',   context: 'user:42');
        $svc->set('ui.theme',    'light');  // global — não deve ser apagado

        $svc->forgetContext('user:42');

        $this->assertNull($svc->get('ui.theme',    context: 'user:42'));
        $this->assertNull($svc->get('ui.language', context: 'user:42'));
        $this->assertSame('light', $svc->get('ui.theme')); // global mantém-se
    }

    // ── all() ─────────────────────────────────────────────────────────────────

    public function test_all_devolve_collection_resolvida(): void
    {
        $svc = $this->service();

        $svc->set('ui.theme',    'light');
        $svc->set('ui.language', 'pt');
        $svc->set('ui.language', 'en', context: 'user:1'); // override

        $all = $svc->all('ui', context: 'user:1');

        $this->assertSame('light', $all['theme']);   // fallback global
        $this->assertSame('en',    $all['language']); // override user
    }

    // ── Inferência de cast ────────────────────────────────────────────────────

    public function test_infere_cast_automaticamente(): void
    {
        $svc = $this->service();

        $svc->set('app.active', true);
        $svc->set('app.count',  42);
        $svc->set('app.ratio',  3.14);
        $svc->set('app.tags',   ['a', 'b']);

        $this->assertIsBool($svc->get('app.active'));
        $this->assertIsInt($svc->get('app.count'));
        $this->assertIsFloat($svc->get('app.ratio'));
        $this->assertIsArray($svc->get('app.tags'));
    }

    // ── Context helpers ───────────────────────────────────────────────────────

    public function test_user_context_com_id_explicito(): void
    {
        $this->assertSame('user:42', SettingsService::userContext(42));
    }

    public function test_tenant_context(): void
    {
        $this->assertSame('tenant:5', SettingsService::tenantContext(5));
    }

    // ── SettingsInheritance ───────────────────────────────────────────────────

    protected function inheritance(): \Gsebastiao\LaravelSettings\Services\SettingsInheritance
    {
        return app(\Gsebastiao\LaravelSettings\Services\SettingsInheritance::class);
    }

    /**
     * Simula um model User real. Estende Eloquent\Model porque
     * SettingsAccessControl e SettingsInheritance fazem type-hint de Model
     * nos parâmetros $user — em produção seria o teu App\Models\User.
     */
    protected function makeUser(int $id): \Illuminate\Database\Eloquent\Model
    {
        return new class($id) extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'users'; // nunca persistido, só para satisfazer o Model

            public function __construct(int $id = null)
            {
                parent::__construct();
                if ($id !== null) {
                    $this->setAttribute('id', $id);
                    $this->exists = true;
                }
            }

            public function getKey(): int
            {
                return (int) $this->getAttribute('id');
            }
        };
    }

    public function test_heranca_so_copia_settings_marcadas_is_inheritable(): void
    {
        // Este é o teste central do requisito: nem tudo o que está em
        // 'global' deve ir para o utilizador. Só is_inheritable=true copia.
        $svc  = $this->service();
        $user = $this->makeUser(1);

        $svc->set('ui.theme', 'light', options: ['is_inheritable' => true]);   // vai
        $svc->set('general.version', '1.0.0');                                  // NÃO vai (default false)
        $svc->set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']); // NÃO vai

        $report = $this->inheritance()->forUser($user);

        $this->assertSame(1, $report['copied']);
        $this->assertSame('light', $svc->get('ui.theme', context: 'user:1'));
        $this->assertNull($svc->get('general.version',     context: 'user:1'));
        $this->assertNull($svc->get('mail.smtp_password',  context: 'user:1'));
    }

    public function test_heranca_propaga_is_inheritable_e_visibility_para_o_user(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(2);

        $svc->set('billing.plan', 'pro', options: [
            'is_inheritable' => true,
            'visibility'     => 'readonly',
        ]);

        $this->inheritance()->forUser($user);

        $copied = Setting::where('context', 'user:2')
            ->where('namespace', 'billing')
            ->where('key', 'plan')
            ->first();

        $this->assertTrue($copied->is_inheritable);
        $this->assertSame('readonly', $copied->visibility);
    }

    public function test_heranca_modo_normal_copia_global_para_user(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(42);

        $svc->set('ui.theme',    'light', options: ['is_inheritable' => true]);
        $svc->set('ui.language', 'pt',    options: ['is_inheritable' => true]);

        $report = $this->inheritance()->forUser($user);

        $this->assertSame(2, $report['copied']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame('light', $svc->get('ui.theme',    context: 'user:42'));
        $this->assertSame('pt',    $svc->get('ui.language', context: 'user:42'));
    }

    public function test_heranca_nao_sobrescreve_settings_existentes(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(42);

        $svc->set('ui.theme', 'light', options: ['is_inheritable' => true]); // global
        $svc->set('ui.theme', 'dark', context: 'user:42');                   // já tem valor próprio

        $report = $this->inheritance()->forUser($user);

        $this->assertSame(0, $report['copied']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame('dark', $svc->get('ui.theme', context: 'user:42')); // mantém o seu
    }

    public function test_heranca_multitenant_tenant_sobrepoe_global(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(99);

        $svc->set('ui.theme',    'light', options: ['is_inheritable' => true]); // global
        $svc->set('ui.language', 'pt',    options: ['is_inheritable' => true]); // global
        $svc->set('ui.theme',    'dark',  context: 'tenant:5', options: ['is_inheritable' => true]); // tenant sobrepõe

        $report = $this->inheritance()->forUser($user, tenantId: 5);

        // theme vem do tenant, language vem do global
        $this->assertSame('dark', $svc->get('ui.theme',    context: 'user:99'));
        $this->assertSame('pt',   $svc->get('ui.language', context: 'user:99'));
        $this->assertSame(2, $report['copied']);
    }

    public function test_heranca_filtrada_por_namespace(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(10);

        $svc->set('ui.theme',       'light', options: ['is_inheritable' => true]);
        $svc->set('mail.from_name', 'App',   options: ['is_inheritable' => true]);

        $report = $this->inheritance()->forUser($user, namespaces: ['ui']);

        $this->assertSame(1, $report['copied']);
        $this->assertSame(['ui'], $report['namespaces']);
        $this->assertNull($svc->get('mail.from_name', context: 'user:10')); // não copiado
    }

    public function test_preview_mostra_o_que_seria_copiado(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(55);

        $svc->set('ui.theme',    'light', options: ['is_inheritable' => true]);
        $svc->set('ui.language', 'pt',    options: ['is_inheritable' => true]);
        $svc->set('ui.theme',    'dark', context: 'user:55'); // já existe

        $preview = $this->inheritance()->preview($user);

        $this->assertSame('copy', $preview['ui.language']['action']);
        $this->assertSame('skip', $preview['ui.theme']['action']);
    }

    public function test_reset_repoe_settings_do_user(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(77);

        $svc->set('ui.theme', 'light', options: ['is_inheritable' => true]);
        $svc->set('ui.theme', 'dark', context: 'user:77'); // personalizado

        $this->inheritance()->resetUser($user);

        // Após reset, deve ter o valor global
        $this->assertSame('light', $svc->get('ui.theme', context: 'user:77'));
    }

    // ── SettingsAccessControl ─────────────────────────────────────────────────

    protected function accessControl(): \Gsebastiao\LaravelSettings\Services\SettingsAccessControl
    {
        return app(\Gsebastiao\LaravelSettings\Services\SettingsAccessControl::class);
    }

    public function test_visibility_padrao_sem_pivot(): void
    {
        $svc = $this->service();
        $svc->set('billing.plan', 'pro', options: ['visibility' => 'readonly']);

        $visibility = $this->accessControl()->visibilityFor('billing.plan', context: 'global');

        $this->assertSame('readonly', $visibility);
    }

    public function test_setting_hidden_por_padrao_nao_e_visivel(): void
    {
        $svc = $this->service();
        $svc->set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);

        $this->assertFalse(
            $this->accessControl()->canView('mail.smtp_password', context: 'global')
        );
    }

    public function test_pivot_sobrepoe_visibility_da_setting_por_user(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(42);

        $svc->set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);

        \Gsebastiao\LaravelSettings\Models\SettingManager::grant(
            'mail.smtp_password',
            context: 'global',
            type: 'user',
            id: 42,
            visibility: 'editable'
        );

        // Sem pivot, qualquer outro user não vê (continua hidden)
        $otherUser = $this->makeUser(99);
        $this->assertFalse($this->accessControl()->canView('mail.smtp_password', context: 'global', user: $otherUser));

        // O user 42 tem grant explícito → vê e edita
        $this->assertTrue($this->accessControl()->canEdit('mail.smtp_password', context: 'global', user: $user));
    }

    public function test_pivot_sobrepoe_visibility_da_setting_por_role(): void
    {
        $svc = $this->service();
        $svc->set('billing.plan', 'pro', options: ['visibility' => 'hidden']);

        \Gsebastiao\LaravelSettings\Models\SettingManager::grant(
            'billing.plan',
            context: 'tenant:5',
            type: 'role',
            id: 'manager',
            visibility: 'readonly'
        );

        // Simula um user com role 'manager' via config resolver
        config(['settings.resolve_roles' => fn ($u) => ['manager']]);

        $managerUser = $this->makeUser(7);

        $this->assertTrue(
            $this->accessControl()->canView('billing.plan', context: 'tenant:5', user: $managerUser)
        );
        $this->assertFalse(
            $this->accessControl()->canEdit('billing.plan', context: 'tenant:5', user: $managerUser)
        ); // readonly, não editable
    }

    public function test_revoke_remove_acesso_do_pivot(): void
    {
        $svc  = $this->service();
        $user = $this->makeUser(1);

        $svc->set('ui.theme', 'dark', options: ['visibility' => 'hidden']);

        \Gsebastiao\LaravelSettings\Models\SettingManager::grant(
            'ui.theme', context: 'global', type: 'user', id: 1, visibility: 'editable'
        );
        $this->assertTrue($this->accessControl()->canView('ui.theme', context: 'global', user: $user));

        \Gsebastiao\LaravelSettings\Models\SettingManager::revoke(
            'ui.theme', context: 'global', type: 'user', id: 1
        );
        $this->assertFalse($this->accessControl()->canView('ui.theme', context: 'global', user: $user));
    }
}
