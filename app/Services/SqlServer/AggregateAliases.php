<?php

namespace App\Services\SqlServer;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Resolves withCount()/withSum() aliases back into the aggregate subqueries
 * that produced them, so SQL can use them where SQL Server can't see aliases.
 *
 * MySQL, MariaDB and SQLite resolve SELECT-list aliases anywhere in ORDER BY
 * and HAVING, e.g. "ORDER BY (accessories.qty - checkouts_count) desc".
 * SQL Server only resolves an alias when it stands alone in ORDER BY, and
 * never in HAVING or WHERE, so it fails with "Invalid column name".
 *
 * @see \App\Models\Traits\InlinesAggregateAliases
 */
class AggregateAliases
{
    /**
     * Build the subquery by running the given aggregate (e.g.
     * fn ($q) => $q->withCount('checkouts as checkouts_count')) on a fresh
     * query for the same model, so relation scopes and bindings match.
     *
     * @return array{0: string, 1: array} the parenthesized subquery and its bindings
     */
    public static function fromAggregate(Builder $query, string $alias, Closure $aggregate): array
    {
        $subquery = self::fromSelect($aggregate($query->getModel()->newQueryWithoutScopes()), $alias);

        if ($subquery === null) {
            throw new LogicException("Aggregate for [{$alias}] did not produce a column aliased as [{$alias}].");
        }

        return $subquery;
    }

    /**
     * Find the subquery already selected under $alias on this query.
     *
     * @return array{0: string, 1: array}|null the parenthesized subquery and its bindings
     */
    public static function fromSelect(Builder $query, string $alias): ?array
    {
        $base = $query->getQuery();
        $grammar = $base->getGrammar();
        $suffix = ' as '.$grammar->wrap($alias);
        $selectBindings = $base->getRawBindings()['select'];
        $offset = 0;

        foreach ($base->columns ?? [] as $column) {
            if (! $grammar->isExpression($column)) {
                continue;
            }

            $sql = (string) $grammar->getValue($column);
            $placeholders = substr_count($sql, '?');

            if (str_ends_with($sql, $suffix)) {
                return [
                    substr($sql, 0, -strlen($suffix)),
                    array_slice($selectBindings, $offset, $placeholders),
                ];
            }

            $offset += $placeholders;
        }

        return null;
    }

    /**
     * Replace each alias in $sql with its subquery, interleaving the
     * subquery bindings with the caller's ? placeholders in order.
     *
     * @param  array<string, array{0: string, 1: array}>  $subqueries
     * @return array{0: string, 1: array}
     */
    public static function inline(string $sql, array $subqueries, array $bindings = []): array
    {
        $names = implode('|', array_map(fn ($alias) => preg_quote($alias, '/'), array_keys($subqueries)));
        $pattern = '/\?|(?<![\w.\[])('.$names.')(?![\w\]])/';

        $mergedBindings = [];
        $sql = preg_replace_callback($pattern, function ($match) use ($subqueries, &$bindings, &$mergedBindings) {
            if ($match[0] === '?') {
                $mergedBindings[] = array_shift($bindings);

                return '?';
            }

            [$subquerySql, $subqueryBindings] = $subqueries[$match[1]];
            array_push($mergedBindings, ...$subqueryBindings);

            return $subquerySql;
        }, $sql);

        return [$sql, $mergedBindings];
    }
}
