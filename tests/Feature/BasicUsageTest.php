<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Contracts\SettingsRepository;
use Gsebastiao\LaravelSettings\Exceptions\SettingLockedException;
use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Services\SettingsService;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use InvalidArgumentException;

class BasicUsageTest extends TestCase
{
    public function test_get_devolve_null_ou_o_valor_por_defeito(): void
    {
        $this->assertNull(Settings::get('app.name'));
        $this->assertSame('Minha App', Settings::get('app.name', 'Minha App'));
        $this->assertSame('calculado', Settings::get('app.name', fn () => 'calculado'));
    }

    public function test_set_grava_e_get_le(): void
    {
        $setting = Settings::set('app.name', 'Loja do Zé');

        $this->assertInstanceOf(Setting::class, $setting);
        $this->assertTrue($setting->exists);
        $this->assertSame('Loja do Zé', Settings::get('app.name'));
        $this->assertSame('Loja do Zé', setting('app.name'));
        $this->assertDatabaseHas('settings', ['namespace' => 'app', 'key' => 'name', 'context' => 'global']);
    }

    public function test_actualizar_mantem_uma_so_linha(): void
    {
        Settings::set('app.name', 'Versão 1');
        Settings::set('app.name', 'Versão 2');

        $this->assertSame('Versão 2', Settings::get('app.name'));
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_has_e_forget(): void
    {
        $this->assertFalse(Settings::has('app.name'));

        Settings::set('app.name', 'Loja');
        $this->assertTrue(Settings::has('app.name'));

        $this->assertTrue(Settings::forget('app.name'));
        $this->assertFalse(Settings::has('app.name'));
        $this->assertNull(Settings::get('app.name'));
        $this->assertFalse(Settings::forget('app.name'));
    }

    public function test_setting_gravada_com_null_existe(): void
    {
        Settings::set('app.logo', null);

        $this->assertTrue(Settings::has('app.logo'));
        $this->assertNull(Settings::get('app.logo', 'default.png'));
    }

    public function test_all_devolve_as_settings_de_um_grupo(): void
    {
        Settings::set('ui.theme', 'light');
        Settings::set('ui.language', 'pt');
        Settings::set('mail.from', 'loja@example.com');

        $this->assertSame(['language' => 'pt', 'theme' => 'light'], Settings::all('ui')->all());
        $this->assertSame([], Settings::all('nada')->all());
    }

    public function test_grupos_com_varios_niveis(): void
    {
        Settings::set('format.date_time.date', 'd/m/Y');

        $this->assertSame('d/m/Y', Settings::get('format.date_time.date'));
        $this->assertSame(['date' => 'd/m/Y'], Settings::all('format.date_time')->all());
        $this->assertDatabaseHas('settings', ['namespace' => 'format.date_time', 'key' => 'date']);
    }

    public function test_find_devolve_o_registo_completo(): void
    {
        Settings::set('ui.language', 'pt', options: ['metadata' => ['input' => 'select', 'options' => ['pt', 'en']]]);

        $setting = Settings::find('ui.language');

        $this->assertSame('pt', $setting->value);
        $this->assertSame(['pt', 'en'], $setting->metadata['options']);
        $this->assertSame('ui.language', $setting->getDotKey());
        $this->assertNull(Settings::find('ui.nada'));
    }

    public function test_set_many_grava_varias_de_uma_vez(): void
    {
        Settings::setMany(['ui.theme' => 'dark', 'ui.font_size' => 16]);

        $this->assertSame('dark', Settings::get('ui.theme'));
        $this->assertSame(16, Settings::get('ui.font_size'));
    }

    public function test_set_many_e_tudo_ou_nada(): void
    {
        Settings::set('app.version', '1.0', options: ['is_locked' => true]);

        try {
            Settings::setMany(['ui.theme' => 'dark', 'app.version' => '2.0'], context: 'user:1');
            $this->fail('Devia ter lançado SettingLockedException');
        } catch (SettingLockedException) {
            // esperado
        }

        $this->assertDatabaseMissing('settings', ['key' => 'theme']);
        $this->assertNull(Settings::get('ui.theme', context: 'user:1'));
    }

    public function test_chave_invalida_da_erro_claro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Usa o formato 'grupo.nome'");

        Settings::get('semponto');
    }

    public function test_opcao_desconhecida_da_erro_claro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Opção desconhecida em 'ui.theme': inheritable");

        Settings::set('ui.theme', 'dark', options: ['inheritable' => true]);
    }

    public function test_visibility_invalida_da_erro_claro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Visibilidade inválida');

        Settings::set('ui.theme', 'dark', options: ['visibility' => 'secret']);
    }

    public function test_facade_helper_e_injeccao_usam_o_mesmo_servico(): void
    {
        $this->assertSame(app(SettingsService::class), app(SettingsRepository::class));
        $this->assertSame(app('settings'), setting());
        $this->assertSame(app(SettingsService::class), Settings::getFacadeRoot());
    }
}
