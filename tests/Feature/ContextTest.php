<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Gsebastiao\LaravelSettings\Tests\Fixtures\User;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use RuntimeException;

class ContextTest extends TestCase
{
    public function test_contexto_do_utilizador_sobrepoe_o_global(): void
    {
        Settings::set('ui.language', 'pt');
        Settings::set('ui.language', 'en', context: 'user:42');

        $this->assertSame('pt', Settings::get('ui.language'));
        $this->assertSame('en', Settings::get('ui.language', context: 'user:42'));
    }

    public function test_sem_valor_proprio_usa_o_global(): void
    {
        Settings::set('ui.language', 'pt');

        $this->assertSame('pt', Settings::get('ui.language', context: 'user:99'));
        $this->assertSame(['language' => 'pt'], Settings::all('ui', context: 'user:99')->all());
    }

    public function test_api_for_user(): void
    {
        $user = User::withId(42);

        Settings::set('ui.theme', 'light');
        Settings::forUser($user)->set('ui.theme', 'dark');

        $this->assertSame('dark', Settings::forUser($user)->get('ui.theme'));
        $this->assertSame('light', Settings::forUser(User::withId(7))->get('ui.theme'));
        $this->assertSame('light', Settings::get('ui.theme'));
        $this->assertSame('user:42', Settings::forUser($user)->context());
        $this->assertDatabaseHas('settings', ['key' => 'theme', 'context' => 'user:42']);
    }

    public function test_utilizador_tenant_global(): void
    {
        $user = User::withId(1);

        Settings::set('ui.theme', 'light');
        Settings::set('ui.logo', 'global.png');
        Settings::set('ui.language', 'pt');
        Settings::forTenant(5)->set('ui.theme', 'tenant-theme');
        Settings::forTenant(5)->set('ui.logo', 'tenant.png');
        Settings::forUser($user)->set('ui.theme', 'user-theme');

        $prefs = Settings::forUser($user, tenant: 5);

        $this->assertSame(['user:1', 'tenant:5', 'global'], $prefs->chain());
        $this->assertSame('user-theme', $prefs->get('ui.theme'));
        $this->assertSame('tenant.png', $prefs->get('ui.logo'));
        $this->assertSame('pt', $prefs->get('ui.language'));
        $this->assertSame(
            ['language' => 'pt', 'logo' => 'tenant.png', 'theme' => 'user-theme'],
            $prefs->all('ui')->all()
        );
        $this->assertSame('tenant.png', Settings::get('ui.logo', context: ['user:1', 'tenant:5']));
    }

    public function test_user_setting_usa_o_utilizador_autenticado(): void
    {
        Settings::set('ui.theme', 'light');

        // Visitante: recebe o valor global, sem erro
        $this->assertSame('light', userSetting('ui.theme'));

        $user = User::withId(3);
        Settings::forUser($user)->set('ui.theme', 'dark');
        $this->actingAs($user);

        $this->assertSame('dark', userSetting('ui.theme'));
        $this->assertSame('dark', Settings::forUser()->get('ui.theme'));
        $this->assertSame('user:3', Settings::userContext());
        $this->assertSame('light', userSetting('ui.theme', user: User::withId(4)));
    }

    public function test_ids_em_texto_como_uuid(): void
    {
        $this->actingAs(User::withId(1));

        // Na 1.2.2, um ID em texto era ignorado e devolvia 'user:1' (o autenticado)!
        $this->assertSame('user:9b1d2c3e-uuid', SettingsService::userContext('9b1d2c3e-uuid'));
        $this->assertSame('user:abc', SettingsService::userContext(User::withId('abc')));
        $this->assertSame('user:42', SettingsService::userContext(42));
        $this->assertSame('tenant:5', SettingsService::tenantContext(5));
    }

    public function test_for_user_sem_utilizador_da_erro_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não há nenhum utilizador autenticado');

        Settings::forUser();
    }

    public function test_tenant_setting(): void
    {
        Settings::set('ui.logo', 'global.png');
        Settings::forTenant(5)->set('ui.logo', 'tenant.png');

        $this->assertSame('tenant.png', tenantSetting('ui.logo', tenantId: 5));
        $this->assertSame('global.png', tenantSetting('ui.logo', tenantId: 6));
        $this->assertSame('global.png', tenantSetting('ui.logo'));
    }

    public function test_forget_context_apaga_so_esse_contexto(): void
    {
        Settings::set('ui.theme', 'dark', context: 'user:42');
        Settings::set('ui.language', 'en', context: 'user:42');
        Settings::set('ui.theme', 'light');

        $this->assertSame(2, Settings::forgetContext('user:42'));

        // O utilizador deixa de ter valores próprios e passa a ver os globais
        $this->assertSame('light', Settings::get('ui.theme', context: 'user:42'));
        $this->assertNull(Settings::get('ui.language', context: 'user:42'));
        $this->assertSame('light', Settings::get('ui.theme'));
    }

    public function test_contexto_personalizado(): void
    {
        Settings::forContext('shop:3')->set('shop.currency', 'MZN');

        $this->assertSame('MZN', Settings::get('shop.currency', context: 'shop:3'));
        $this->assertNull(Settings::get('shop.currency'));
    }

    public function test_prefixos_configuraveis(): void
    {
        config(['settings.contexts.user' => 'member', 'settings.contexts.tenant' => 'company']);

        $this->assertSame('member:1', SettingsService::userContext(1));
        $this->assertSame('company:2', SettingsService::tenantContext(2));
    }
}
