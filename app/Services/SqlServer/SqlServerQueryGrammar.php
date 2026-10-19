<?php

namespace App\Services\SqlServer;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Support\Str;

/**
 * SQL Server query grammar tweaks for this fork, smoothing over query shapes
 * and value formats that upstream Snipe-IT (developed against MySQL) relies on.
 */
class SqlServerQueryGrammar extends SqlServerGrammar
{
    /**
     * Write date-times as "Y-m-d H:i:s" (Laravel's SQL Server default adds
     * ".v" milliseconds), matching MySQL's DATETIME and the date_format rules
     * Snipe-IT validates model attributes with. See SqlServerConnection::select().
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Honor limit() on updates, as MySQL's UPDATE ... LIMIT does. Laravel
     * compiles it for SQL Server deletes ("delete top (n)") but drops it from
     * updates, and a soft delete is an update: License::adjustSeatCount()
     * soft-deletes `->limit($change)` free seats, which otherwise removed
     * every free seat.
     */
    protected function compileUpdateWithoutJoins(Builder $query, $table, $columns, $where)
    {
        $sql = parent::compileUpdateWithoutJoins($query, $table, $columns, $where);

        return ! is_null($query->limit) && $query->limit > 0 && $query->offset <= 0
            ? Str::replaceFirst('update', 'update top ('.(int) $query->limit.')', $sql)
            : $sql;
    }

    /**
     * MySQL tolerates the same column appearing more than once in an ORDER BY;
     * SQL Server rejects it ("A column has been specified more than once in
     * the order by list"). The pattern is common upstream — e.g.
     * Asset::checkouts() chains an orderBy('created_at') onto assetlog(),
     * which already orders by it.
     *
     * Rather than patching every relation (and re-patching on each upstream
     * merge), drop repeated column orders here. The first occurrence wins,
     * which is exactly how the database would have applied them anyway:
     * a later sort on an already-sorted column can never change the result.
     */
    protected function compileOrdersToArray(Builder $query, $orders)
    {
        $seenColumns = [];

        $orders = array_filter($orders, function ($order) use (&$seenColumns) {
            // Raw orders may carry positional bindings; never drop them.
            if (isset($order['sql']) || ! isset($order['column'])) {
                return true;
            }

            $column = $this->wrap($order['column']);

            if (isset($seenColumns[$column])) {
                return false;
            }

            $seenColumns[$column] = true;

            return true;
        });

        return parent::compileOrdersToArray($query, array_values($orders));
    }

    /**
     * Drop exact repeats from the select list. Upstream sometimes lists a
     * column twice (e.g. 'locations.tag_color' in the locations API), which
     * MySQL tolerates; SQL Server then rejects any ORDER BY on that column
     * name as ambiguous. Rows are keyed by column name, so the repeat never
     * reached the result anyway.
     */
    protected function compileColumns(Builder $query, $columns)
    {
        $seenColumns = [];

        $columns = array_filter($columns, function ($column) use (&$seenColumns) {
            if (! is_string($column)) {
                return true;
            }

            $wrapped = $this->wrap($column);

            if (isset($seenColumns[$wrapped])) {
                return false;
            }

            $seenColumns[$wrapped] = true;

            return true;
        });

        return parent::compileColumns($query, array_values($columns));
    }
}
