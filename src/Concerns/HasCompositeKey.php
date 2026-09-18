<?php

namespace Gsebastiao\LaravelSettings\Concerns;

use Gsebastiao\LaravelSettings\Database\CompositeKeyBuilder;
use LogicException;

/**
 * Suporte a chave primária composta (várias colunas) em models Eloquent.
 *
 * ── Porque existe ────────────────────────────────────────────────────────────
 *
 * O Eloquent assume uma chave primária de UMA coluna. Com `$primaryKey = null`
 * (como estes models tinham até à 1.2.2) o Eloquent não sabia identificar o
 * registo e as operações de instância geravam queries SEM cláusula WHERE. Por
 * exemplo, actualizar uma setting existente com Settings::set() executava:
 *
 *   UPDATE settings SET value = 'dark', updated_at = ...   ← TODAS as linhas!
 *
 * Este trait substitui os pontos onde o Eloquent monta esse WHERE
 * (setKeysForSelectQuery e setKeysForSaveQuery), usados por save(), update(),
 * delete(), forceDelete(), restore(), refresh(), fresh(), increment() e
 * decrement(). O CompositeKeyBuilder trata de find(), whereKey(), chunk() e
 * da serialização em jobs.
 *
 * ── Como usar ────────────────────────────────────────────────────────────────
 *
 *   class Setting extends Model
 *   {
 *       use HasCompositeKey;
 *
 *       protected $primaryKey = null;
 *       public $incrementing = false;
 *       protected array $compositeKey = ['namespace', 'key', 'context'];
 *   }
 *
 * @property array<int, string> $compositeKey
 */
trait HasCompositeKey
{
    /**
     * Colunas que, juntas, identificam um registo.
     *
     * @return array<int, string>
     */
    public function getCompositeKey(): array
    {
        return $this->compositeKey;
    }

    /**
     * Identificador único do registo, em JSON: '["ui","theme","global"]'.
     *
     * Usado pelo Eloquent em colecções (unique(), contains(), find()...), em
     * Model::is() e ao serializar o model para um job. Com a chave primária a
     * null todos os registos tinham o mesmo "id" (null): unique() de 10
     * settings devolvia 1.
     */
    public function getKey()
    {
        $values = [];

        foreach ($this->getCompositeKey() as $column) {
            $value = $this->getAttributeFromArray($column);

            if ($value === null) {
                return null;
            }

            $values[] = (string) $value;
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * WHERE usado para ler o registo actual (refresh(), fresh()).
     */
    protected function setKeysForSelectQuery($query)
    {
        foreach ($this->getCompositeKey() as $column) {
            $query->where($column, '=', $this->getCompositeKeyValueForQuery($column));
        }

        return $query;
    }

    /**
     * WHERE usado para gravar/apagar o registo actual (save(), delete()...).
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->setKeysForSelectQuery($query);
    }

    /**
     * Valor ORIGINAL da coluna — se alterares `context` em memória e fizeres
     * save(), o registo actualizado é o que foi lido, não o novo.
     *
     * Nunca devolve null: sem o valor da chave, a query não teria WHERE e
     * afectaria a tabela inteira. Nesse caso preferimos parar com uma
     * mensagem clara.
     */
    protected function getCompositeKeyValueForQuery(string $column): mixed
    {
        $value = array_key_exists($column, $this->original)
            ? $this->original[$column]
            : $this->getAttributeFromArray($column);

        if ($value === null) {
            throw new LogicException(sprintf(
                '[gsebastiao/laravel-settings] Não é possível identificar o registo de %s: '
                . 'falta a coluna "%s" da chave composta (%s).',
                static::class,
                $column,
                implode(', ', $this->getCompositeKey())
            ));
        }

        return $value;
    }

    /**
     * O Model::delete() do Eloquent recusa models sem chave primária simples
     * ("No primary key defined on model"), mas a remoção propriamente dita
     * passa por setKeysForSaveQuery() — que aqui já usa a chave composta. Por
     * isso basta satisfazer essa verificação inicial durante a chamada.
     */
    public function delete()
    {
        if ($this->getKeyName() !== null) {
            return parent::delete();
        }

        $this->primaryKey = $this->getCompositeKey()[0];

        try {
            return parent::delete();
        } finally {
            $this->primaryKey = null;
        }
    }

    /**
     * Builder que entende a chave composta (find, whereKey, chunk, jobs).
     */
    public function newEloquentBuilder($query)
    {
        return new CompositeKeyBuilder($query);
    }
}
