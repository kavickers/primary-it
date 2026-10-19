<?php

namespace App\Models\Traits;

use App\Services\SqlServer\AggregateAliases;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lets Eloquent query builders reference withCount()/withSum() aliases
 * inside ORDER BY / HAVING expressions on every supported database.
 *
 * Every driver except SQL Server runs the original SQL unchanged. SQL Server
 * can't see SELECT aliases inside expressions, so there each alias is
 * replaced with the aggregate subquery that produced it (see AggregateAliases).
 *
 * Usage: pass a map of alias => closure that applies the aggregate:
 *
 *     $query->orderByAggregateAliases('(accessories.qty - checkouts_count) desc', [
 *         'checkouts_count' => fn ($q) => $q->withCount('checkouts as checkouts_count'),
 *     ]);
 *
 * @mixin Builder
 */
trait InlinesAggregateAliases
{
    /**
     * @param  array<string, Closure(Builder): Builder>  $aliases
     */
    public function orderByAggregateAliases(string $sql, array $aliases, array $bindings = []): self
    {
        if ($this->getConnection()->getDriverName() !== 'sqlsrv') {
            return $this->orderByRaw($sql, $bindings);
        }

        return $this->orderByRaw(...$this->inlineAggregateAliases($sql, $aliases, $bindings));
    }

    /**
     * On SQL Server the condition becomes a WHERE clause: with the aliases
     * inlined it no longer depends on any aggregate of the outer query.
     *
     * @param  array<string, Closure(Builder): Builder>  $aliases
     */
    public function havingAggregateAliases(string $sql, array $aliases, array $bindings = []): self
    {
        if ($this->getConnection()->getDriverName() !== 'sqlsrv') {
            return $this->havingRaw($sql, $bindings);
        }

        return $this->whereRaw(...$this->inlineAggregateAliases($sql, $aliases, $bindings));
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function inlineAggregateAliases(string $sql, array $aliases, array $bindings): array
    {
        $subqueries = [];
        foreach ($aliases as $alias => $aggregate) {
            $subqueries[$alias] = AggregateAliases::fromAggregate($this, $alias, $aggregate);
        }

        return AggregateAliases::inline($sql, $subqueries, $bindings);
    }
}
