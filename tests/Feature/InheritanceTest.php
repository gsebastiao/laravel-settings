<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Services\SettingsInheritance;
use Gsebastiao\LaravelSettings\Tests\Fixtures\User;
use Gsebastiao\LaravelSettings\Tests\TestCase;

class InheritanceTest extends TestCase
{
    private function inheritance(): SettingsInheritance
    {
        return app(SettingsInheritance::class);
    }

    public function test_so_copia_settings_herdaveis(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('general.version', '1.0.0');
        Settings::set('mail.smtp_password', 'secret', options: ['visibility' => 'hidden']);

        $report = $this->inheritance()->forUser(User::withId(1));

        $this->assertSame(1, $report['copied']);
        $this->assertDatabaseHas('settings', ['key' => 'theme', 'context' => 'user:1', 'value' => 'light']);
        $this->assertDatabaseMissing('settings', ['key' => 'version', 'context' => 'user:1']);
        $this->assertDatabaseMissing('settings', ['key' => 'smtp_password', 'context' => 'user:1']);
    }

    public function test_propaga_is_inheritable_e_visibility(): void
    {
        Settings::set('billing.plan', 'pro', options: ['is_inheritable' => true, 'visibility' => 'readonly']);

        $this->inheritance()->forUser(User::withId(2));

        $copied = Setting::whereDotKey('billing.plan')->forContext('user:2')->first();
        $this->assertTrue($copied->is_inheritable);
        $this->assertSame('readonly', $copied->visibility);
        $this->assertFalse($copied->is_locked);
    }

    public function test_copia_do_global_para_o_utilizador(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.language', 'pt', options: ['is_inheritable' => true]);

        $report = $this->inheritance()->forUser(User::withId(42));

        $this->assertSame(['copied' => 2, 'skipped' => 0, 'namespaces' => ['ui']], $report);

        // A cópia não muda quando o global muda
        Settings::set('ui.theme', 'dark');
        $this->assertSame('light', Settings::get('ui.theme', context: 'user:42'));
        $this->assertSame('pt', Settings::get('ui.language', context: 'user:42'));
    }

    public function test_nao_sobrescreve_valores_do_utilizador(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'user:42');

        $report = $this->inheritance()->forUser(User::withId(42));

        $this->assertSame(0, $report['copied']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame('dark', Settings::get('ui.theme', context: 'user:42'));
    }

    public function test_tenant_sobrepoe_global(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.language', 'pt', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'tenant:5', options: ['is_inheritable' => true]);

        $report = $this->inheritance()->forUser(User::withId(99), tenantId: 5);

        $this->assertSame(2, $report['copied']);
        $this->assertDatabaseHas('settings', ['key' => 'theme', 'context' => 'user:99', 'value' => 'dark']);
        $this->assertDatabaseHas('settings', ['key' => 'language', 'context' => 'user:99', 'value' => 'pt']);
    }

    public function test_valor_nao_herdavel_do_tenant_tem_prioridade(): void
    {
        $user = User::withId(1);
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'tenant:5'); // não herdável

        $report = $this->inheritance()->forUser($user, tenantId: 5);

        $this->assertSame(0, $report['copied']);
        $this->assertSame('dark', Settings::forUser($user, tenant: 5)->get('ui.theme'));
    }

    public function test_filtrar_por_grupo(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('mail.from_name', 'Loja', options: ['is_inheritable' => true]);

        $report = $this->inheritance()->forUser(User::withId(10), namespaces: ['ui']);

        $this->assertSame(1, $report['copied']);
        $this->assertSame(['ui'], $report['namespaces']);
        $this->assertDatabaseMissing('settings', ['key' => 'from_name', 'context' => 'user:10']);
    }

    public function test_preview_nao_altera_nada(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.language', 'pt', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'user:55');

        $preview = $this->inheritance()->preview(User::withId(55));

        $this->assertSame('copy', $preview['ui.language']['action']);
        $this->assertSame('pt', $preview['ui.language']['value']);
        $this->assertSame('global', $preview['ui.language']['source']);
        $this->assertSame('skip', $preview['ui.theme']['action']);
        $this->assertDatabaseMissing('settings', ['key' => 'language', 'context' => 'user:55']);
    }

    public function test_reset_repoe_os_valores_herdados(): void
    {
        // Na 1.2.2 este método nem compilava (ParseError)
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'user:77');

        $report = $this->inheritance()->resetUser(User::withId(77));

        $this->assertSame(1, $report['copied']);
        $this->assertSame('light', Settings::get('ui.theme', context: 'user:77'));
    }

    public function test_copia_depois_de_forget_no_utilizador(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);
        Settings::set('ui.theme', 'dark', context: 'user:1');
        Settings::forget('ui.theme', context: 'user:1');

        $report = $this->inheritance()->forUser(User::withId(1));

        $this->assertSame(1, $report['copied']);
        $this->assertSame('light', Settings::get('ui.theme', context: 'user:1'));
        $this->assertSame(1, Setting::withTrashed()->forContext('user:1')->count());
    }

    public function test_aceita_um_id_em_vez_do_model(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_inheritable' => true]);

        $this->assertSame(1, $this->inheritance()->forUser(42)['copied']);
        $this->assertDatabaseHas('settings', ['key' => 'theme', 'context' => 'user:42']);
    }
}
