<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheTest extends TestCase
{
    public function test_varias_leituras_no_mesmo_pedido_fazem_uma_query(): void
    {
        Settings::set('app.name', 'Loja');
        Settings::set('app.url', 'https://loja.test');
        $this->newRequest();

        DB::enableQueryLog();
        DB::flushQueryLog();

        Settings::get('app.name');
        Settings::get('app.url');
        setting('app.name');
        setting('app.nada', 'x');
        Settings::has('app.url');

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_pedido_seguinte_le_da_cache_sem_ir_a_base_de_dados(): void
    {
        Settings::set('app.name', 'Loja');
        Settings::get('app.name');
        $this->newRequest();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('Loja', Settings::get('app.name'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_gravar_limpa_a_cache(): void
    {
        Settings::set('app.name', 'Loja');
        Settings::get('app.name');
        $this->newRequest();

        Settings::set('app.name', 'Loja Nova');
        $this->newRequest();

        $this->assertSame('Loja Nova', Settings::get('app.name'));
    }

    public function test_alteracoes_pelo_model_tambem_limpam_a_cache(): void
    {
        Settings::set('app.name', 'Loja');
        Settings::get('app.name');

        $setting = Setting::whereDotKey('app.name')->first();
        $setting->value = 'Loja Nova';
        $setting->save();

        $this->assertSame('Loja Nova', Settings::get('app.name'));
        $this->newRequest();
        $this->assertSame('Loja Nova', Settings::get('app.name'));

        $setting->delete();
        $this->assertNull(Settings::get('app.name'));
    }

    public function test_clear_cache_depois_de_alterar_a_tabela_a_mao(): void
    {
        Settings::set('app.name', 'Loja');
        Settings::get('app.name');

        DB::table('settings')->update(['value' => 'Alterado à mão']);
        $this->newRequest();
        $this->assertSame('Loja', Settings::get('app.name')); // ainda em cache

        $this->artisan('settings:clear-cache')->assertSuccessful()->run();
        $this->newRequest();

        $this->assertSame('Alterado à mão', Settings::get('app.name'));
    }

    public function test_cache_desligada(): void
    {
        config(['settings.cache.enabled' => false]);
        $this->newRequest();

        Settings::set('app.name', 'Loja');
        Settings::get('app.name');
        DB::table('settings')->update(['value' => 'Alterado à mão']);
        $this->newRequest();

        $this->assertSame('Alterado à mão', Settings::get('app.name'));
    }

    public function test_cache_e_limpa_de_novo_depois_do_commit(): void
    {
        Settings::set('app.name', 'Loja');

        DB::transaction(function (): void {
            Settings::set('app.name', 'Loja Nova');

            // Simula outro pedido que leu a BD antes do COMMIT e pôs o valor antigo em cache
            Cache::put('settings:v1:global', ['format' => 2, 'rows' => ['app.name' => [
                'namespace' => 'app', 'key' => 'name', 'context' => 'global',
                'value' => 'Loja', 'cast' => 'string', 'is_locked' => 0,
            ]]]);
        });

        $this->newRequest();
        $this->assertSame('Loja Nova', Settings::get('app.name'));
    }
}
