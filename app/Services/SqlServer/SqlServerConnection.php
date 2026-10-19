<?php

namespace App\Services\SqlServer;

use Illuminate\Database\SqlServerConnection as BaseSqlServerConnection;
use PDOStatement;

/**
 * SQL Server connection that uses this fork's query builder and grammars,
 * and returns values shaped like MySQL's.
 *
 * Registered for the `sqlsrv` driver in AppServiceProvider::register().
 */
class SqlServerConnection extends BaseSqlServerConnection
{
    /**
     * Matches a date-time string with fractional seconds, as pdo_sqlsrv
     * returns DATETIME / DATETIME2 values ("2026-10-01 09:30:00.000").
     */
    private const FRACTIONAL_DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{1,7}$/';

    public function query()
    {
        return new SqlServerQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Upstream Snipe-IT treats date-times as "Y-m-d H:i:s" strings — MySQL's
     * DATETIME shape — validating raw values with date_format:Y-m-d H:i:s
     * (e.g. Asset::$rules['last_checkout'] and DATETIME custom fields) and
     * handing them to date pickers. pdo_sqlsrv instead returns fractional
     * seconds, so a record read back from the database fails validation the
     * next time it is saved. Trim them on read, as MySQL would.
     *
     * pdo_sqlsrv also returns BIGINT columns as strings, even with
     * SQLSRV_ATTR_FETCHES_NUMERIC_TYPE on, where MySQL returns ints. Upstream
     * uses BIGINT for its newer tables and foreign keys (foreignId(),
     * bigIncrements()) and compares those ids strictly, so cast them back.
     *
     * And fixed-width CHAR / NCHAR values come back padded with spaces, which
     * MySQL strips on read. Upstream declares several settings with char()
     * (digit_separator, skin, logo paths...) and compares them as-is, e.g.
     * `digit_separator == '1.234,56'`, so strip the padding.
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->executeSelect($query, $bindings, $useReadPdo);

            return $this->normalizeRecords($statement->fetchAll(), static::columnTypes($statement));
        });
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $statement = $this->executeSelect($query, $bindings, $useReadPdo);
            $sets = [];

            do {
                $sets[] = $this->normalizeRecords($statement->fetchAll(), static::columnTypes($statement));
            } while ($statement->nextRowset());

            return $sets;
        });
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $statement = $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            return $this->executeSelect($query, $bindings, $useReadPdo);
        });

        if (! $statement instanceof PDOStatement) {
            return;
        }

        $columnTypes = static::columnTypes($statement);

        while ($record = $statement->fetch()) {
            yield static::normalizeColumns(static::trimFractionalSeconds($record), $columnTypes);
        }
    }

    /**
     * The BIGINT and fixed-width CHAR columns in the statement's current
     * result set, keyed by column name.
     *
     * @return array<string, 'bigint'|'char'>
     */
    public static function columnTypes(PDOStatement $statement): array
    {
        $types = [];

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index);
            $declaredType = strtolower($meta['sqlsrv:decl_type'] ?? '');

            if (str_starts_with($declaredType, 'bigint')) {
                $types[$meta['name']] = 'bigint';
            } elseif ($declaredType === 'char' || $declaredType === 'nchar') {
                $types[$meta['name']] = 'char';
            }
        }

        return $types;
    }

    /**
     * Cast BIGINT values to int and strip CHAR padding, as MySQL returns them.
     *
     * @param  object|array  $record
     * @param  array<string, 'bigint'|'char'>  $columnTypes
     * @return object|array
     */
    public static function normalizeColumns($record, array $columnTypes)
    {
        foreach ($columnTypes as $column => $type) {
            $value = is_array($record) ? ($record[$column] ?? null) : ($record->{$column} ?? null);

            if (! is_string($value)) {
                continue;
            }

            $value = $type === 'bigint' ? (int) $value : rtrim($value, ' ');

            if (is_array($record)) {
                $record[$column] = $value;
            } else {
                $record->{$column} = $value;
            }
        }

        return $record;
    }

    /**
     * @param  object|array  $record
     * @return object|array
     */
    public static function trimFractionalSeconds($record)
    {
        foreach ($record as $key => $value) {
            if (is_string($value) && isset($value[19]) && $value[19] === '.'
                && preg_match(self::FRACTIONAL_DATETIME, $value)) {
                if (is_array($record)) {
                    $record[$key] = substr($value, 0, 19);
                } else {
                    $record->{$key} = substr($value, 0, 19);
                }
            }
        }

        return $record;
    }

    private function executeSelect(string $query, array $bindings, bool $useReadPdo): PDOStatement
    {
        $statement = $this->prepared($this->getPdoForSelect($useReadPdo)->prepare($query));

        $this->bindValues($statement, $this->prepareBindings($bindings));

        $statement->execute();

        return $statement;
    }

    /**
     * @param  array<int, object|array>  $records
     * @param  array<string, 'bigint'|'char'>  $columnTypes
     * @return array<int, object|array>
     */
    private function normalizeRecords(array $records, array $columnTypes): array
    {
        return array_map(
            fn ($record) => static::normalizeColumns(static::trimFractionalSeconds($record), $columnTypes),
            $records
        );
    }

    protected function getDefaultQueryGrammar()
    {
        return new SqlServerQueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new SqlServerSchemaGrammar($this);
    }
}
