<?php

namespace Gsebastiao\LaravelSettings\Tests\Feature;

use DateTimeInterface;
use Gsebastiao\LaravelSettings\Facades\Settings;
use Gsebastiao\LaravelSettings\Tests\Fixtures\Theme;
use Gsebastiao\LaravelSettings\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class CastTest extends TestCase
{
    #[DataProvider('valoresComTipoDeduzido')]
    public function test_tipo_deduzido_do_valor(mixed $value, string $cast): void
    {
        $setting = Settings::set('app.value', $value);

        $this->assertSame($cast, $setting->cast);
        $this->assertSame($value, Settings::get('app.value'));
    }

    public static function valoresComTipoDeduzido(): array
    {
        return [
            'texto' => ['Olá', 'string'],
            'inteiro' => [14, 'int'],
            'decimal' => [3.5, 'float'],
            'verdadeiro' => [true, 'bool'],
            'falso' => [false, 'bool'],
            'lista' => [['pt', 'en'], 'json'],
            'mapa' => [['a' => 1, 'b' => ['c' => true]], 'json'],
        ];
    }

    public function test_tipo_explicito(): void
    {
        Settings::set('ui.font_size', '14', cast: 'int');
        Settings::set('app.ratio', '2.5', cast: 'float');
        Settings::set('app.enabled', 'on', cast: 'boolean');
        Settings::set('app.tags', '["a","b"]', cast: 'json');

        $this->assertSame(14, Settings::get('ui.font_size'));
        $this->assertSame(2.5, Settings::get('app.ratio'));
        $this->assertTrue(Settings::get('app.enabled'));
        $this->assertSame(['a', 'b'], Settings::get('app.tags'));
        $this->assertSame('bool', Settings::find('app.enabled')->cast);
    }

    public function test_texto_de_formulario_mantem_o_tipo(): void
    {
        Settings::set('ui.font_size', 14);
        Settings::set('ui.font_size', '16'); // $request->font_size chega como texto
        $this->assertSame(16, Settings::get('ui.font_size'));

        Settings::set('app.maintenance', false);
        Settings::set('app.maintenance', 'on');
        $this->assertTrue(Settings::get('app.maintenance'));

        Settings::set('shop.price', 3.5);
        Settings::set('shop.price', 4); // inteiro numa setting decimal
        Settings::set('shop.price', '4.75');
        $this->assertSame(4.75, Settings::get('shop.price'));
    }

    public function test_valor_que_nao_serve_para_o_tipo_da_erro_claro(): void
    {
        Settings::set('ui.font_size', 14);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Não foi possível gravar 'ui.font_size'");

        Settings::set('ui.font_size', 'grande');
    }

    public function test_tipo_desconhecido_da_erro_claro(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tipo (cast) 'money' não suportado");

        Settings::set('shop.price', 10, cast: 'money');
    }

    public function test_datas(): void
    {
        $date = Carbon::parse('2026-03-01 10:30:00', 'Africa/Maputo');
        Settings::set('app.launch', $date);

        $read = Settings::get('app.launch');
        $this->assertInstanceOf(DateTimeInterface::class, $read);
        $this->assertTrue($read->equalTo($date));

        // Alterar o objecto devolvido não altera o valor guardado
        $read->addYear();
        $this->assertTrue(Settings::get('app.launch')->equalTo($date));

        Settings::set('app.deadline', '2026-12-31', cast: 'date');
        $this->assertSame('2026-12-31', Settings::get('app.deadline')->format('Y-m-d'));

        Settings::set('app.deadline', ''); // campo de data vazio num formulário
        $this->assertNull(Settings::get('app.deadline'));
    }

    public function test_datas_gravadas_pela_versao_1(): void
    {
        // A 1.x gravava as datas entre aspas e a leitura rebentava
        $this->insertRaw(['namespace' => 'app', 'key' => 'launch', 'value' => '"2026-01-15T08:00:00.000000Z"', 'cast' => 'date']);

        $this->assertSame('2026-01-15', Settings::get('app.launch')->format('Y-m-d'));
    }

    public function test_enums(): void
    {
        Settings::set('ui.theme', Theme::Dark);

        $this->assertSame('dark', Settings::get('ui.theme'));
        $this->assertSame(Theme::Dark, Theme::from(Settings::get('ui.theme')));
    }

    public function test_tipo_desconhecido_na_base_de_dados_e_lido_como_texto(): void
    {
        $this->insertRaw(['namespace' => 'app', 'key' => 'legacy', 'value' => '42', 'cast' => 'weird']);

        $this->assertSame('42', Settings::get('app.legacy'));
    }

    private function insertRaw(array $row): void
    {
        DB::table('settings')->insert($row + [
            'context' => 'global',
            'is_locked' => false,
            'is_inheritable' => false,
            'visibility' => 'editable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
