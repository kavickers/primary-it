<?php

namespace App\Services\SqlServer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Fluent;

/**
 * SQL Server schema grammar tweaks for this fork's migrations.
 */
class SqlServerSchemaGrammar extends SqlServerGrammar
{
    /**
     * Adding a nullable column with a default fills existing rows with that
     * default on MySQL, but leaves them NULL on SQL Server unless the column
     * is added "WITH VALUES". Upstream adds many flags that way, e.g.
     * custom_fields.show_in_listview (`boolean()->nullable()->default(0)`),
     * and rows that predate the column then fail their model's `boolean`
     * validation — the seeded "MAC Address" custom field could not be saved.
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        $sql = parent::modifyDefault($blueprint, $column);

        return $sql !== null && $column->nullable && ! $blueprint->creating()
            ? $sql.' with values'
            : $sql;
    }
}
