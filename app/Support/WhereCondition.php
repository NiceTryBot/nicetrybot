<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A query's WHERE as one SQL condition with its bindings, so another query can count the rows it keeps
 * (sum(case when … then 1 else 0 end)) in the same pass instead of running it apart.
 */
readonly class WhereCondition
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(public string $sql, public array $bindings)
    {
        //
    }

    /**
     * The WHERE of a query that has nothing else: no joins, selects or groups.
     *
     * @param  Builder<*>|QueryBuilder  $query
     */
    public static function of(Builder|QueryBuilder $query): self
    {
        $query = $query instanceof Builder ? $query->toBase() : $query;
        $where = preg_replace('/^where /', '', $query->getGrammar()->compileWheres($query));

        return new self($where === '' ? '1 = 1' : "({$where})", array_values($query->getBindings()));
    }

    /**
     * A select expression counting the rows the condition keeps.
     */
    public function countAs(string $alias): string
    {
        return "sum(case when {$this->sql} then 1 else 0 end) as {$alias}";
    }
}
