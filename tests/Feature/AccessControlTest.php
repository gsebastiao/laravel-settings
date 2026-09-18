<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Models\SettingManager;
use Gsebastiao\LaravelSettings\Services\SettingsAccessControl;
use Gsebastiao\LaravelSettings\Tests\Fixtures\User;
use Gsebastiao\LaravelSettings\Tests\Fixtures\UserWithRoles;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class AccessControlTest extends TestCase
{
    private function access(): SettingsAccessControl
    {
        return app(SettingsAccessControl::class);
    }

    public function test_sem_permissoes_usa_a_visibility_da_setting(): void
    {
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'readonly']);

        $this->assertSame('readonly', $this->access()->visibilityFor('billing.plan', context: 'global'));
        $this->assertSame('readonly', $this->access()->visibilityFor('billing.plan'));
        $this->assertSame('hidden', $this->access()->visibilityFor('billing.nada'));
    }

    public function test_setting_hidden_nao_e_visivel(): void
    {
        Settings::set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);

        $this->assertFalse($this->access()->canView('mail.smtp_password', context: 'global'));
    }

    public function test_permissao_por_utilizador(): void
    {
        Settings::set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);
        SettingManager::grant('mail.smtp_password', context: 'global', type: 'user', id: 42, visibility: 'editable');

        $this->assertFalse($this->access()->canView('mail.smtp_password', context: 'global', user: User::withId(99)));
        $this->assertTrue($this->access()->canEdit('mail.smtp_password', context: 'global', user: User::withId(42)));
    }

    public function test_permissao_por_role_num_tenant(): void
    {
        // A setting está no global e a permissão foi dada no tenant 5 (falhava na 1.2.2)
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'hidden']);
        SettingManager::grant('billing.plan', context: 'tenant:5', type: 'role', id: 'manager', visibility: 'readonly');

        config(['settings.resolve_roles' => fn ($user) => ['manager']]);
        $manager = User::withId(7);

        $this->assertTrue($this->access()->canView('billing.plan', context: 'tenant:5', user: $manager));
        $this->assertFalse($this->access()->canEdit('billing.plan', context: 'tenant:5', user: $manager));
        // A permissão vale só no contexto em que foi dada
        $this->assertFalse($this->access()->canView('billing.plan', context: 'global', user: $manager));
    }

    public function test_revoke_retira_a_permissao(): void
    {
        Settings::set('ui.theme', 'dark', options: ['visibility' => 'hidden']);
        $user = User::withId(1);

        SettingManager::grant('ui.theme', context: 'global', type: 'user', id: 1, visibility: 'editable');
        $this->assertTrue($this->access()->canView('ui.theme', context: 'global', user: $user));

        $this->assertTrue(SettingManager::revoke('ui.theme', context: 'global', type: 'user', id: 1));
        $this->assertFalse($this->access()->canView('ui.theme', context: 'global', user: $user));
        $this->assertFalse(SettingManager::revoke('ui.theme', context: 'global', type: 'user', id: 1));
    }

    public function test_grupos_com_varios_niveis(): void
    {
        // Na 1.2.2 devolvia 'hidden': a chave era cortada no primeiro ponto
        Settings::set('format.date_time.date', 'd/m/Y', options: ['visibility' => 'readonly']);

        $this->assertSame('readonly', $this->access()->visibilityFor('format.date_time.date', 'global'));
    }

    public function test_setting_bloqueada_e_readonly_nos_contextos_abaixo(): void
    {
        Settings::set('app.version', '1.0.0', options: ['is_locked' => true]);

        $this->assertSame('editable', $this->access()->visibilityFor('app.version'));
        $this->assertSame('readonly', $this->access()->visibilityFor('app.version', context: 'user:1'));
    }

    public function test_roles_com_resolve_roles_using(): void
    {
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'hidden']);
        SettingManager::grant('billing.plan', 'global', 'role', 'admin', 'editable');
        SettingManager::grant('billing.plan', 'global', 'role', 'viewer', 'readonly');

        // Devolver uma Collection (sem ->all()) também funciona
        SettingsAccessControl::resolveRolesUsing(fn ($user) => collect(['viewer', 'admin']));

        $this->assertSame('editable', $this->access()->visibilityFor('billing.plan', user: User::withId(1)));
    }

    public function test_permissao_do_utilizador_ganha_a_do_role(): void
    {
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'hidden']);
        SettingManager::grant('billing.plan', 'global', 'role', 'admin', 'editable');
        SettingManager::grant('billing.plan', 'global', 'user', 5, 'readonly');
        SettingsAccessControl::resolveRolesUsing(fn () => ['admin']);

        $this->assertSame('readonly', $this->access()->visibilityFor('billing.plan', user: User::withId(5)));
    }

    public function test_get_role_names_do_spatie(): void
    {
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'hidden']);
        SettingManager::grant('billing.plan', 'global', 'role', 'manager');

        $user = UserWithRoles::withId(8);
        $user->roleNames = ['manager'];

        $this->assertTrue($this->access()->canView('billing.plan', user: $user));
    }

    public function test_filter_visible_faz_uma_so_query(): void
    {
        Settings::set('a.one', 1, options: ['visibility' => 'hidden']);
        Settings::set('a.two', 2, options: ['visibility' => 'hidden']);
        Settings::set('a.three', 3);
        SettingManager::grant('a.one', 'global', 'user', 1);

        $user = User::withId(1);
        $access = $this->access();
        $access->canView('a.three', user: $user); // verifica a tabela uma vez

        $settings = Setting::forNamespace('a')->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $visible = $access->filterVisible($settings, $user);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(['one', 'three'], $visible->pluck('key')->sort()->values()->all());
    }

    public function test_grant_valida_os_valores(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Usa 'user' ou 'role'");

        SettingManager::grant('ui.theme', 'global', 'group', 1);
    }

    public function test_sem_a_tabela_de_permissoes(): void
    {
        Schema::drop('settings_managers');
        Settings::set('billing.plan', 'pro', options: ['visibility' => 'readonly']);

        $this->assertSame('readonly', $this->access()->visibilityFor('billing.plan', user: User::withId(1)));
        $this->assertFalse(SettingManager::revoke('billing.plan', 'global', 'user', 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php artisan migrate');
        SettingManager::grant('billing.plan', 'global', 'user', 1);
    }
}
