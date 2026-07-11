<?php

namespace Gsebastiao\LaravelSettings\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast dinâmico: lê a coluna 'cast' do próprio registo para determinar
 * como converter o valor de texto guardado na BD para o tipo PHP correcto.
 *
 * Tipos suportados: string | int | float | bool | json | array | date
 */
class SettingValueCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($attributes['cast'] ?? 'string') {
            'int'           => (int) $value,
            'float'         => (float) $value,
            'bool'          => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode($value, true),
            'date'          => Carbon::parse($value),
            default         => (string) $value,
        };
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
