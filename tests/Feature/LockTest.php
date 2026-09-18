<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Exceptions\SettingLockedException;
use Gsebastiao\LaravelSettings\Exceptions\SettingNotFoundException;
use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Tests\Fixtures\User;
use Gsebastiao\LaravelSettings\Tests\TestCase;

class LockTest extends TestCase
{
    public function test_lock_impede_valores_proprios(): void
    {
        Settings::set('app.version', '1.0.0');
        Settings::set('app.version', '9.9.9', context: 'user:1'); // criado antes do bloqueio
        Settings::lock('app.version');

        // Leitura: o bloqueio ganha ao valor do utilizador (na 1.2.2 devolvia 9.9.9)
        $this->assertSame('1.0.0', Settings::get('app.version', context: 'user:1'));
        $this->assertTrue(Settings::isLocked('app.version', context: 'user:1'));

        // Escrita: é recusada com uma mensagem clara
        try {
            Settings::set('app.version', '2.0.0', context: 'user:1');
            $this->fail('Devia ter lançado SettingLockedException');
        } catch (SettingLockedException $e) {
            $this->assertSame('app.version', $e->dotKey);
            $this->assertSame('user:1', $e->context);
            $this->assertSame('global', $e->lockedIn);
            $this->assertStringContainsString("Settings::unlock('app.version'", $e->getMessage());
        }
    }

    public function test_o_contexto_que_bloqueou_pode_actualizar(): void
    {
        Settings::set('app.version', '1.0.0', options: ['is_locked' => true]);
        Settings::set('app.version', '1.1.0');

        $this->assertSame('1.1.0', Settings::get('app.version', context: 'user:1'));
        $this->assertTrue(Settings::find('app.version')->is_locked);
    }

    public function test_unlock_repoe_os_valores_proprios(): void
    {
        Settings::set('app.version', '1.0.0');
        Settings::set('app.version', '9.9.9', context: 'user:1');
        Settings::lock('app.version');
        Settings::unlock('app.version');

        $this->assertFalse(Settings::isLocked('app.version', context: 'user:1'));
        $this->assertSame('9.9.9', Settings::get('app.version', context: 'user:1'));

        Settings::set('app.version', '8.0.0', context: 'user:1');
        $this->assertSame('8.0.0', Settings::get('app.version', context: 'user:1'));
    }

    public function test_bloqueio_no_tenant(): void
    {
        $user = User::withId(1);

        Settings::set('ui.theme', 'light');
        Settings::forTenant(5)->set('ui.theme', 'corporate', options: ['is_locked' => true]);

        $this->assertSame('corporate', Settings::forUser($user, tenant: 5)->get('ui.theme'));

        try {
            Settings::forUser($user, tenant: 5)->set('ui.theme', 'dark');
            $this->fail('Devia ter lançado SettingLockedException');
        } catch (SettingLockedException $e) {
            $this->assertSame('tenant:5', $e->lockedIn);
        }
    }

    public function test_o_bloqueio_mais_geral_ganha(): void
    {
        Settings::forTenant(5)->set('ui.theme', 'tenant', options: ['is_locked' => true]);
        Settings::set('ui.theme', 'global', options: ['is_locked' => true]);

        $this->assertSame('global', Settings::forUser(User::withId(1), tenant: 5)->get('ui.theme'));
        $this->assertSame('global', Settings::forTenant(5)->get('ui.theme'));
    }

    public function test_set_sem_opcoes_nao_desbloqueia(): void
    {
        Settings::set('app.version', '1.0.0');
        Settings::lock('app.version');
        Settings::set('app.version', '1.0.1');

        $this->assertTrue(Settings::isLocked('app.version'));

        $this->expectException(SettingLockedException::class);
        Settings::set('app.version', 'x', context: 'user:1');
    }

    public function test_lock_de_setting_que_nao_existe(): void
    {
        $this->expectException(SettingNotFoundException::class);
        $this->expectExceptionMessage("Não existe a setting 'app.nada' no contexto 'global'");

        Settings::lock('app.nada');
    }

    public function test_lock_no_contexto_do_utilizador(): void
    {
        $prefs = Settings::forUser(User::withId(1));
        $prefs->set('ui.theme', 'dark');
        $prefs->lock('ui.theme');

        $this->assertTrue($prefs->isLocked('ui.theme'));
        $this->assertFalse(Settings::isLocked('ui.theme'));
    }
}
