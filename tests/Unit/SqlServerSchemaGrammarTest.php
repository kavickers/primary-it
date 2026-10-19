<?php

namespace Tests\Unit;

use App\Services\SqlServer\SqlServerConnection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class SqlServerSchemaGrammarTest extends TestCase
{
    private function compile(callable $callback, bool $create = false): array
    {
        // The PDO resolver is never invoked: toSql() only compiles.
        $connection = new SqlServerConnection(fn () => null, 'snipeit', '', ['driver' => 'sqlsrv']);
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, 'custom_fields', function (Blueprint $table) use ($callback, $create) {
            if ($create) {
                $table->create();
            }
            $callback($table);
        });

        return $blueprint->toSql();
    }

    public function test_added_nullable_columns_fill_existing_rows_with_their_default()
    {
        $this->assertSame(
            ['alter table "custom_fields" add "show_in_listview" bit null default \'0\' with values'],
            str_replace(['[', ']'], '"', $this->compile(fn (Blueprint $table) => $table->boolean('show_in_listview')->nullable()->default(0)))
        );
    }

    public function test_columns_without_a_default_or_in_a_new_table_are_unchanged()
    {
        $this->assertStringNotContainsString(
            'with values',
            implode(';', $this->compile(fn (Blueprint $table) => $table->boolean('show_in_listview')->nullable()))
        );
        $this->assertStringNotContainsString(
            'with values',
            implode(';', $this->compile(fn (Blueprint $table) => $table->boolean('show_in_listview')->nullable()->default(0), create: true))
        );
    }
}
