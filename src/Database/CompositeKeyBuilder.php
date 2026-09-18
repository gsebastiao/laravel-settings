<?php

namespace Gsebastiao\LaravelSettings\Database;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Query builder para models com chave primária composta (ver HasCompositeKey).
 *
 * Faz funcionar o que no Eloquent depende de uma chave de uma só coluna:
 *
 *   Setting::find($setting->getKey());                  // '["ui","theme","global"]'
 *   Setting::whereKey(['namespace' => 'ui', 'key' => 'theme', 'context' => 'global'])->first();
 *   Setting::query()->chunk(100, fn ($settings) => ...); // ordena pela chave composta
 *
 * e também a serialização de models em jobs (SerializesModels), que usa
 * whereKey() por baixo.
 */
class CompositeKeyBuilder extends Builder
{
    public function whereKey($id)
    {
        $this->whereCompositeKeys($id, not: false);

        return $this;
    }

    public function whereKeyNot($id)
    {
        $this->whereCompositeKeys($id, not: true);

        return $this;
    }

    /**
     * chunk(), each() e lazy() precisam de uma ordem estável: sem orderBy
     * explícito, ordenamos pelas colunas da chave composta.
     */
    protected function enforceOrderBy()
    {
        if (empty($this->query->orders) && empty($this->query->unionOrders)) {
            foreach ($this->model->getCompositeKey() as $column) {
                $this->orderBy($this->model->qualifyColumn($column), 'asc');
            }
        }
    }

    protected function whereCompositeKeys(mixed $id, bool $not): void
    {
        $keys = $this->normalizeCompositeKeys($id);
        $columns = $this->model->getCompositeKey();

        if ($keys === []) {
            if (! $not) {
                $this->query->whereRaw('0 = 1');
            }

            return;
        }

        $constraint = function ($query) use ($keys, $columns): void {
            foreach ($keys as $values) {
                $query->orWhere(function ($query) use ($values, $columns): void {
                    foreach ($columns as $index => $column) {
                        $query->where($this->model->qualifyColumn($column), '=', $values[$index]);
                    }
                });
            }
        };

        $not ? $this->query->whereNot($constraint) : $this->query->where($constraint);
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function normalizeCompositeKeys(mixed $id): array
    {
        if ($id instanceof Model || is_string($id) || (is_array($id) && $id !== [] && ! array_is_list($id))) {
            $id = [$id];
        } elseif ($id instanceof Arrayable) {
            $id = $id->toArray();
        }

        if (! is_array($id)) {
            throw $this->invalidCompositeKey($id);
        }

        return array_map(fn (mixed $one): array => $this->compositeValues($one), array_values($id));
    }

    /**
     * @return array<int, string>
     */
    protected function compositeValues(mixed $one): array
    {
        $columns = $this->model->getCompositeKey();
        $original = $one;

        if ($one instanceof Model) {
            $one = $one->getKey();
        }

        if (is_string($one)) {
            $decoded = json_decode($one, true);
            $one = is_array($decoded) ? $decoded : null;
        }

        if (is_array($one) && ! array_is_list($one)) {
            $one = array_map(fn (string $column): mixed => $one[$column] ?? null, $columns);
        }

        if (! is_array($one) || count($one) !== count($columns) || in_array(null, $one, true)) {
            throw $this->invalidCompositeKey($original);
        }

        return array_map(fn (mixed $value): string => (string) $value, array_values($one));
    }

    protected function invalidCompositeKey(mixed $value): InvalidArgumentException
    {
        $columns = $this->model->getCompositeKey();

        return new InvalidArgumentException(sprintf(
            '[gsebastiao/laravel-settings] Chave inválida para %s: %s. Usa o valor de $model->getKey() '
            . '(ex.: \'%s\') ou um array com as colunas %s.',
            $this->model::class,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            json_encode(array_map(fn (string $column): string => '...', $columns)),
            implode(', ', $columns)
        ));
    }
}
