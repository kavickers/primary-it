<?php

namespace App\Services\SqlServer;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * SQL Server query builder tweaks for this fork.
 *
 * SQL Server caps a statement at 2100 bound parameters (MySQL allows 65535),
 * and upstream Snipe-IT routinely passes plucked id lists to whereIn() —
 * e.g. every soft-deleted user id, or every checkout row in a report's date
 * range. Large lists of plain integers are compiled inline instead, exactly
 * like Laravel's own whereIntegerInRaw() (which Eloquent already uses for
 * whereKey() and eager loading). Only true PHP ints qualify, so string
 * values keep being bound as parameters. Multi-row inserts are chunked
 * under the same cap.
 *
 * SQL Server also refuses to compare an integer column with a string it
 * can't convert, where MySQL coerces the string and simply finds no match.
 * See where(). And it only accepts an explicit value for an identity column
 * under SET IDENTITY_INSERT, which inserts with an `id` switch on. Updates
 * that set a NOT NULL column to NULL store the type's empty value, like
 * non-strict MySQL. See update().
 */
class SqlServerQueryBuilder extends Builder
{
    /**
     * Lists at least this long are inlined. Well under the 2100 cap so that
     * a couple of large lists in one statement still fit alongside the
     * query's other bindings.
     */
    public const INLINE_INTEGER_LIST_THRESHOLD = 500;

    /**
     * Most parameters a single chunked INSERT statement binds.
     */
    public const MAX_INSERT_BINDINGS = 2000;

    /**
     * Value ranges of SQL Server's integer column types.
     */
    private const INTEGER_TYPE_RANGES = [
        'tinyint' => [0, 255],
        'smallint' => [-32768, 32767],
        'int' => [-2147483648, 2147483647],
        'bigint' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    /**
     * The value MySQL stores for NULL in a NOT NULL column of each type.
     * Date-time and uniqueidentifier columns have no SQL Server equivalent
     * of MySQL's zero date, so they're left to fail as before.
     */
    private const IMPLICIT_DEFAULTS = [
        'char' => '', 'nchar' => '', 'varchar' => '', 'nvarchar' => '', 'text' => '', 'ntext' => '',
        'bit' => 0, 'tinyint' => 0, 'smallint' => 0, 'int' => 0, 'bigint' => 0,
        'decimal' => 0, 'numeric' => 0, 'float' => 0, 'real' => 0, 'money' => 0, 'smallmoney' => 0,
    ];

    /**
     * Each table's id column, per connection and database.
     *
     * @var array<string, array{type: string, identity: bool}|null>
     */
    private static array $idColumns = [];

    /**
     * Each table's NOT NULL columns and their implicit defaults, per
     * connection and database.
     *
     * @var array<string, array<string, string|int>>
     */
    private static array $implicitDefaults = [];

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        if (is_array($values)
            && count($values) >= self::INLINE_INTEGER_LIST_THRESHOLD
            && count(array_filter($values, 'is_int')) === count($values)) {
            return $this->whereIntegerInRaw($column, $values, $boolean, $not);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    /**
     * Ids from user input reach queries as strings: route parameters, API
     * payloads, and validation rules such as `unique_undeleted:assets,asset_tag`
     * validated outside the model, where the id slot still holds a column name.
     * MySQL coerces "does-not-exist" (or an id too large for the column) and
     * finds no match, so upstream answers 404 / 422. SQL Server raises a
     * conversion error instead, which surfaces as a 500.
     *
     * When an integer `id` column is compared for (in)equality with a value
     * SQL Server can't convert, compile the outcome MySQL would reach: `=`
     * matches nothing and `!=` / `<>` matches everything.
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_string($column) && preg_match('/(^|\.)\[?id\]?$/i', $column)) {
            [$comparedValue, $comparison] = func_num_args() === 2 ? [$operator, '='] : [$value, $operator];

            if (is_string($comparedValue)
                && in_array($comparison, ['=', '!=', '<>'], true)
                && $this->isUnrepresentableId($column, $comparedValue)) {
                return $this->whereRaw($comparison === '=' ? '1 = 0' : '1 = 1', [], $boolean);
            }
        }

        return parent::where(...func_get_args());
    }

    public function insert(array $values)
    {
        $firstRow = reset($values);
        $rows = is_array($firstRow) ? $values : [$values];

        return $this->withIdentityInsert($rows, function () use ($values, $firstRow) {
            if (! is_array($firstRow) || count($values) * count($firstRow) <= self::MAX_INSERT_BINDINGS) {
                return parent::insert($values);
            }

            $rowsPerStatement = max(1, intdiv(self::MAX_INSERT_BINDINGS, count($firstRow)));

            return $this->connection->transaction(function () use ($values, $rowsPerStatement) {
                foreach (array_chunk($values, $rowsPerStatement) as $chunk) {
                    parent::insert($chunk);
                }

                return true;
            });
        });
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return $this->withIdentityInsert([$values], fn () => parent::insertGetId($values, $sequence));
    }

    /**
     * Upstream runs MySQL with 'strict' => false, where an UPDATE that sets a
     * NOT NULL column to NULL stores the type's implicit default ('' or 0)
     * instead of failing. Upstream relies on it: settings forms assign
     * $request->input() for empty text inputs and unchecked checkboxes, which
     * arrive as null. SQL Server rejects the whole statement, so substitute
     * the value MySQL would have stored.
     */
    public function update(array $values)
    {
        foreach ($values as $column => $value) {
            if ($value !== null || ! is_string($column)) {
                continue;
            }

            $table = $this->resolveTable($column);
            $columnName = trim(Str::afterLast($column, '.'), '[]');
            $implicitDefaults = $table === null ? [] : $this->implicitDefaults($table);

            if (array_key_exists($columnName, $implicitDefaults)) {
                $values[$column] = $implicitDefaults[$columnName];
            }
        }

        return parent::update($values);
    }

    /**
     * Run an insert with IDENTITY_INSERT on when it supplies explicit ids
     * for an identity column, as MySQL accepts for AUTO_INCREMENT columns.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function withIdentityInsert(array $rows, Closure $insert): mixed
    {
        $table = is_string($this->from) ? $this->resolveTable('id') : null;
        $suppliesIds = count(array_filter($rows, fn ($row) => isset($row['id']))) > 0;

        if ($table === null || ! $suppliesIds || ! ($this->idColumn($table)['identity'] ?? false)) {
            return $insert();
        }

        $wrappedTable = $this->grammar->wrapTable($table);
        $this->connection->unprepared("set identity_insert {$wrappedTable} on");

        try {
            return $insert();
        } finally {
            $this->connection->unprepared("set identity_insert {$wrappedTable} off");
        }
    }

    /**
     * Whether $value can't be stored in the integer id column $column.
     * Columns that aren't integers (e.g. Passport's string token ids), or
     * whose table can't be resolved, are left alone.
     */
    private function isUnrepresentableId(string $column, string $value): bool
    {
        $table = $this->resolveTable($column);
        $range = $table === null ? null : (self::INTEGER_TYPE_RANGES[$this->idColumn($table)['type'] ?? ''] ?? null);

        if ($range === null) {
            return false;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false || $integer < $range[0] || $integer > $range[1];
    }

    /**
     * @return array{type: string, identity: bool}|null
     */
    private function idColumn(string $table): ?array
    {
        $cacheKey = $this->connection->getName().'|'.$this->connection->getDatabaseName().'|'.$table;

        if (! array_key_exists($cacheKey, self::$idColumns)) {
            $row = $this->connection->selectOne(
                "select data_type, columnproperty(object_id(quotename(table_schema) + '.' + quotename(table_name)), column_name, 'IsIdentity') as is_identity
                from information_schema.columns where table_name = ? and column_name = ?",
                [$this->connection->getTablePrefix().$table, 'id']
            );

            self::$idColumns[$cacheKey] = $row
                ? ['type' => strtolower($row->data_type), 'identity' => (int) $row->is_identity === 1]
                : null;
        }

        return self::$idColumns[$cacheKey];
    }

    /**
     * @return array<string, string|int>
     */
    private function implicitDefaults(string $table): array
    {
        $cacheKey = $this->connection->getName().'|'.$this->connection->getDatabaseName().'|'.$table;

        if (! array_key_exists($cacheKey, self::$implicitDefaults)) {
            $columns = $this->connection->select(
                "select column_name, data_type from information_schema.columns where table_name = ? and is_nullable = 'NO'",
                [$this->connection->getTablePrefix().$table]
            );

            self::$implicitDefaults[$cacheKey] = [];

            foreach ($columns as $column) {
                $type = strtolower($column->data_type);

                if (array_key_exists($type, self::IMPLICIT_DEFAULTS)) {
                    self::$implicitDefaults[$cacheKey][$column->column_name] = self::IMPLICIT_DEFAULTS[$type];
                }
            }
        }

        return self::$implicitDefaults[$cacheKey];
    }

    /**
     * The table a (possibly qualified or aliased) id column belongs to.
     */
    private function resolveTable(string $column): ?string
    {
        $from = is_string($this->from) ? preg_split('/\s+as\s+/i', trim($this->from)) : null;
        $qualifier = str_contains($column, '.') ? trim(substr($column, 0, strrpos($column, '.')), '[]') : null;

        if ($qualifier === null || ($from !== null && $qualifier === ($from[1] ?? null))) {
            return $from[0] ?? null;
        }

        return $qualifier;
    }
}
