<?php

namespace Gsebastiao\LaravelSettings\Casts;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Converte o valor de uma setting entre o texto guardado na base de dados e
 * o tipo PHP indicado na coluna `cast` do próprio registo.
 *
 *   cast    | guardado na BD              | devolvido em PHP
 *   --------|-----------------------------|------------------
 *   string  | Minha Loja                  | 'Minha Loja'
 *   int     | 14                          | 14
 *   float   | 3.5                         | 3.5
 *   bool    | 1 / 0                       | true / false
 *   json    | ["pt","en"]                 | ['pt', 'en']
 *   array   | (igual a json)              | (igual a json)
 *   date    | 2026-01-31T10:00:00+02:00   | Carbon
 *
 * Também aceita os nomes usados nos $casts do Eloquent: integer, boolean,
 * double, real e datetime.
 */
class SettingValueCast implements CastsAttributes
{
    /** Tipos suportados na coluna `cast`. */
    public const TYPES = ['string', 'int', 'float', 'bool', 'json', 'array', 'date'];

    /** Sinónimos aceites (os nomes que já usas nos $casts do Eloquent). */
    public const ALIASES = [
        'integer' => 'int',
        'boolean' => 'bool',
        'double' => 'float',
        'real' => 'float',
        'datetime' => 'date',
    ];

    /**
     * Cada leitura devolve um objecto novo (ex.: uma data). Assim, alterar o
     * valor devolvido — $data->addDay() — não altera o valor guardado.
     */
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return static::decode($value, $attributes['cast'] ?? null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return static::encode($value, $attributes['cast'] ?? null);
    }

    // ── Nomes de tipo ─────────────────────────────────────────────────────────

    /**
     * Valida e normaliza um nome de tipo recebido pela API ('integer' → 'int').
     *
     * @throws InvalidArgumentException quando o tipo não é suportado
     */
    public static function normalize(?string $cast): ?string
    {
        if ($cast === null) {
            return null;
        }

        $normalized = strtolower(trim($cast));
        $normalized = self::ALIASES[$normalized] ?? $normalized;

        if (! in_array($normalized, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                "[gsebastiao/laravel-settings] Tipo (cast) '%s' não suportado. Usa um destes: %s.",
                $cast,
                implode(', ', self::TYPES)
            ));
        }

        return $normalized;
    }

    /**
     * Versão tolerante de normalize(), usada na LEITURA: valores antigos ou
     * desconhecidos na coluna `cast` são tratados como texto em vez de
     * rebentarem a página.
     */
    public static function readType(?string $cast): string
    {
        $cast = strtolower(trim((string) $cast));
        $cast = self::ALIASES[$cast] ?? $cast;

        return in_array($cast, self::TYPES, true) ? $cast : 'string';
    }

    /**
     * Deduz o tipo a partir do valor PHP. Devolve null quando não há nada a
     * deduzir (texto, null...).
     */
    public static function infer(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value instanceof DateTimeInterface => 'date',
            is_array($value), $value instanceof JsonSerializable, $value instanceof Arrayable => 'json',
            default => null,
        };
    }

    // ── Leitura: texto da base de dados → valor PHP ───────────────────────────

    public static function decode(mixed $value, ?string $cast): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (static::readType($cast)) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode((string) $value, true),
            'date' => static::decodeDate((string) $value),
            default => (string) $value,
        };
    }

    protected static function decodeDate(string $value): ?DateTimeInterface
    {
        // As versões 1.x gravavam as datas entre aspas ("2026-01-01T..."), o
        // que fazia a leitura rebentar. Aceitamos os dois formatos.
        $value = trim($value, " \t\n\r\0\x0B\"");

        if ($value === '') {
            return null;
        }

        try {
            return Date::parse($value);
        } catch (Throwable) {
            return null; // valor corrompido: não rebenta a página inteira
        }
    }

    // ── Escrita: valor PHP → texto para a base de dados ───────────────────────

    /**
     * @throws InvalidArgumentException quando o valor não serve para o tipo
     *                                  (ex.: 'abc' numa setting 'int')
     */
    public static function encode(mixed $value, ?string $cast = null): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        if ($value === null) {
            return null;
        }

        return match ($cast === null ? null : static::readType($cast)) {
            'int' => static::encodeInt($value),
            'float' => static::encodeFloat($value),
            'bool' => static::encodeBool($value),
            'json', 'array' => static::encodeJson($value),
            'date' => static::encodeDate($value),
            default => static::encodeText($value),
        };
    }

    protected static function encodeInt(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null; // campo vazio num formulário
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        $int = is_float($value) ? false : filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false) {
            throw static::invalid($value, 'int', 'um número inteiro (ex.: 14)');
        }

        return (string) $int;
    }

    protected static function encodeFloat(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            if (is_numeric($value)) {
                return $value;
            }
        }

        if (is_bool($value)) {
            $value = (int) $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return var_export($value, true);
        }

        throw static::invalid($value, 'float', 'um número, com ponto como separador decimal (ex.: 3.5)');
    }

    protected static function encodeBool(mixed $value): string
    {
        $bool = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($bool === null) {
            throw static::invalid($value, 'bool', "verdadeiro ou falso (true/false, 1/0, 'on'/'off', 'yes'/'no')");
        }

        return $bool ? '1' : '0';
    }

    protected static function encodeJson(mixed $value): string
    {
        // Texto que já é um objecto ou lista JSON (ex.: vindo de uma textarea)
        // é guardado tal como está.
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && static::isJson($trimmed)) {
                return $trimmed;
            }
        }

        if ($value instanceof Arrayable && ! $value instanceof JsonSerializable) {
            $value = $value->toArray();
        }

        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('o valor não pode ser convertido em JSON (' . $e->getMessage() . ').', 0, $e);
        }
    }

    protected static function encodeDate(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null; // campo de data vazio → sem data (e não "agora")
            }
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        try {
            $date = is_int($value) ? Date::createFromTimestamp($value) : Date::parse((string) $value);
        } catch (Throwable $e) {
            throw static::invalid($value, 'date', "uma data (ex.: '2026-12-31' ou um objecto Carbon)");
        }

        return $date->format(DateTimeInterface::ATOM);
    }

    protected static function encodeText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => static::encodeJson($value),
        };
    }

    protected static function isJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    protected static function invalid(mixed $value, string $type, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            "o valor %s não serve para o tipo '%s' — era esperado %s.",
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            $type,
            $expected
        ));
    }
}
