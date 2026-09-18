<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Exceptions\SettingLockedException;
use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Tests\Fixtures\User;
use Gsebastiao\LaravelSettings\Tests\TestCase;

/**
 * Os exemplos do README, tal como lá estão escritos.
 */
class ReadmeExamplesTest extends TestCase
{
    public function test_primeiros_passos(): void
    {
        Settings::set('app.name', 'Loja do Zé');

        $this->assertSame('Loja do Zé', Settings::get('app.name'));
        $this->assertSame('Bem-vindo', Settings::get('app.slogan', 'Bem-vindo'));

        Settings::set('mail.from_name', 'Loja do Zé');
        Settings::set('mail.from_address', 'geral@loja.co.mz');

        $this->assertSame(
            ['from_address' => 'geral@loja.co.mz', 'from_name' => 'Loja do Zé'],
            Settings::all('mail')->all()
        );
    }

    public function test_settings_por_utilizador(): void
    {
        $ana = User::withId(1);
        $bruno = User::withId(2);

        Settings::set('ui.theme', 'light');
        Settings::forUser($ana)->set('ui.theme', 'dark');

        $this->assertSame('dark', Settings::forUser($ana)->get('ui.theme'));
        $this->assertSame('light', Settings::forUser($bruno)->get('ui.theme'));

        Settings::forUser($ana)->setMany(['ui.theme' => 'light', 'ui.font_size' => 16]);
        $this->assertSame(16, Settings::forUser($ana)->get('ui.font_size', 14));

        Settings::forgetContext(Settings::userContext($ana));
        $this->assertSame(14, Settings::forUser($ana)->get('ui.font_size', 14));
    }

    public function test_bloquear(): void
    {
        $user = User::withId(1);

        Settings::set('app.currency', 'MZN');
        Settings::lock('app.currency');

        $this->assertSame('MZN', Settings::forUser($user)->get('app.currency'));
        $this->assertTrue(Settings::forUser($user)->isLocked('app.currency'));

        Settings::set('app.currency', 'EUR'); // o valor geral continua a poder mudar
        $this->assertSame('EUR', Settings::forUser($user)->get('app.currency'));

        $this->expectException(SettingLockedException::class);
        Settings::forUser($user)->set('app.currency', 'USD');
    }

    public function test_tenants_e_metadata(): void
    {
        $user = User::withId(1);

        Settings::set('ui.logo', 'logos/default.png');
        Settings::forTenant(7)->set('ui.logo', 'logos/acme.png');

        $this->assertSame('logos/acme.png', Settings::forUser($user, tenant: 7)->get('ui.logo'));
        $this->assertSame('logos/acme.png', Settings::get('ui.logo', context: ['user:1', 'tenant:7']));

        Settings::set('ui.language', 'pt', options: [
            'metadata' => ['label' => 'Idioma', 'input' => 'select', 'options' => ['pt' => 'Português', 'en' => 'English']],
        ]);

        $setting = Settings::find('ui.language');
        $this->assertSame('pt', $setting->value);
        $this->assertSame('Idioma', $setting->metadata['label']);
        $this->assertCount(1, Setting::forNamespace('ui')->global()->where('key', 'language')->get());
    }
}
