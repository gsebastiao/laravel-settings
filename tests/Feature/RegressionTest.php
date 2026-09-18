<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Models\Setting;
use Gsebastiao\LaravelSettings\Models\SettingManager;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Um teste por cada bug encontrado na versão 1.2.2.
 */
class RegressionTest extends TestCase
{
    public function test_actualizar_uma_setting_nao_altera_as_outras(): void
    {
        Settings::set('ui.theme', 'light');
        Settings::set('ui.language', 'pt');
        Settings::set('app.name', 'Minha App');

        DB::enableQueryLog();
        Settings::set('ui.theme', 'dark');

        $updates = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'update'));

        $this->assertNotEmpty($updates);

        foreach ($updates as $sql) {
            $this->assertStringContainsString('where', strtolower($sql));
        }

        // Na 1.2.2 as três linhas ficavam com o valor 'dark'
        $this->assertSame(
            ['language' => 'pt', 'name' => 'Minha App', 'theme' => 'dark'],
            DB::table('settings')->orderBy('key')->pluck('value', 'key')->all()
        );
    }

    public function test_forget_e_set_da_mesma_chave(): void
    {
        Settings::set('ui.theme', 'light', options: ['is_locked' => true, 'visibility' => 'readonly']);
        Settings::forget('ui.theme');
        Settings::set('ui.theme', 'dark'); // na 1.2.2: UniqueConstraintViolationException

        $setting = Settings::find('ui.theme');
        $this->assertSame('dark', $setting->value);
        // Volta como nova, sem as opções da versão apagada
        $this->assertFalse($setting->is_locked);
        $this->assertSame('editable', $setting->visibility);
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_set_preserva_as_opcoes_existentes(): void
    {
        Settings::set('ui.theme', 'light', options: [
            'is_locked' => true,
            'is_inheritable' => true,
            'visibility' => 'readonly',
            'metadata' => ['input' => 'select'],
        ]);
        Settings::set('ui.theme', 'dark');

        $setting = Settings::find('ui.theme');
        $this->assertSame('dark', $setting->value);
        $this->assertTrue($setting->is_locked);
        $this->assertTrue($setting->is_inheritable);
        $this->assertSame('readonly', $setting->visibility);
        $this->assertSame(['input' => 'select'], $setting->metadata);

        Settings::set('ui.theme', 'blue', options: ['visibility' => 'editable']);
        $this->assertSame('editable', Settings::find('ui.theme')->visibility);
        $this->assertTrue(Settings::find('ui.theme')->is_locked);
    }

    public function test_actualizar_uma_permissao_nao_altera_as_outras(): void
    {
        SettingManager::grant('ui.theme', 'global', 'user', 1, 'readonly');
        SettingManager::grant('ui.logo', 'global', 'user', 2, 'readonly');
        SettingManager::grant('ui.theme', 'global', 'user', 1, 'editable');

        $this->assertSame('readonly', SettingManager::where('key', 'logo')->first()->visibility);
        $this->assertSame('editable', SettingManager::where('key', 'theme')->first()->visibility);
        $this->assertSame(2, SettingManager::count());
    }

    public function test_revoke_e_grant_de_novo(): void
    {
        SettingManager::grant('ui.theme', 'global', 'user', 1, 'readonly');
        SettingManager::revoke('ui.theme', 'global', 'user', 1);
        SettingManager::grant('ui.theme', 'global', 'user', 1, 'editable');

        $this->assertSame('editable', SettingManager::where('key', 'theme')->first()->visibility);
        $this->assertSame(1, SettingManager::withTrashed()->count());
    }

    public function test_criacao_simultanea_da_mesma_setting(): void
    {
        $inserted = false;

        // Simula outro pedido que cria a mesma setting entre a leitura e a gravação
        Setting::creating(function () use (&$inserted): void {
            if (! $inserted) {
                $inserted = true;
                DB::table('settings')->insert([
                    'namespace' => 'ui', 'key' => 'theme', 'context' => 'global', 'value' => 'outro pedido',
                    'cast' => 'string', 'is_locked' => false, 'is_inheritable' => false, 'visibility' => 'editable',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        Settings::set('ui.theme', 'dark');

        $this->assertTrue($inserted);
        $this->assertSame('dark', Settings::get('ui.theme'));
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_operacoes_eloquent_usam_a_chave_composta(): void
    {
        Settings::set('ui.theme', 'light');
        Settings::set('ui.language', 'pt');
        Settings::set('app.name', 'Loja');

        $theme = Setting::whereDotKey('ui.theme')->first();
        $this->assertSame('["ui","theme","global"]', $theme->getKey());

        $theme->update(['value' => 'dark']);
        $this->assertSame('pt', Settings::get('ui.language'));
        $this->assertSame('dark', Settings::get('ui.theme'));

        $this->assertSame('dark', $theme->fresh()->value);
        $this->assertSame('dark', $theme->refresh()->value);

        $theme->delete();
        $this->assertSoftDeleted('settings', ['namespace' => 'ui', 'key' => 'theme', 'context' => 'global']);
        $this->assertSame('pt', Settings::get('ui.language'));
        $this->assertNull(Settings::get('ui.theme'));

        $theme->restore();
        $this->assertSame('dark', Settings::get('ui.theme'));

        $all = Setting::all();
        $this->assertCount(3, $all->unique());
        $this->assertTrue($all->contains($theme));
        $this->assertTrue(Setting::find($theme->getKey())->is($theme));
        $this->assertSame(1, Setting::whereKey(['namespace' => 'app', 'key' => 'name', 'context' => 'global'])->count());
        $this->assertSame(2, Setting::whereKeyNot($theme)->count());

        $visited = 0;
        Setting::query()->chunk(1, function ($chunk) use (&$visited): void {
            $visited += $chunk->count();
        });
        $this->assertSame(3, $visited);
        $this->assertSame(3, Setting::query()->lazy(2)->count());

        $theme->forceDelete();
        $this->assertDatabaseMissing('settings', ['namespace' => 'ui', 'key' => 'theme']);
        $this->assertDatabaseCount('settings', 2);
    }

    public function test_models_em_jobs_com_serializes_models(): void
    {
        Settings::set('ui.theme', 'light');
        Settings::set('ui.language', 'pt');

        $settings = Setting::forNamespace('ui')->get();
        $job = unserialize(serialize(new JobWithSettings($settings->first(), $settings)));

        $this->assertTrue($job->setting->is($settings->first()));
        $this->assertCount(2, $job->settings);
    }

    public function test_chave_composta_invalida_da_erro_claro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chave inválida');

        Setting::find('ui.theme');
    }
}

class JobWithSettings
{
    use SerializesModels;

    public function __construct(public Setting $setting, public EloquentCollection $settings)
    {
    }
}
