<?php

namespace Tests\Unit;

use App\Services\SqlServer\SqlServerConnection;
use PHPUnit\Framework\TestCase;

class SqlServerQueryGrammarTest extends TestCase
{
    private function query()
    {
        // The PDO resolver is never invoked: toSql() only compiles.
        $connection = new SqlServerConnection(fn () => null, 'snipeit', '', ['driver' => 'sqlsrv']);

        return $connection->query()->from('action_logs');
    }

    public function test_limited_updates_only_touch_that_many_rows()
    {
        $query = $this->query()->whereNull('target_id')->limit(5);

        $this->assertSame(
            'update top (5) [action_logs] set [note] = ? where [target_id] is null',
            $query->getGrammar()->compileUpdate($query, ['note' => 'x'])
        );
        $this->assertSame(
            'update [action_logs] set [note] = ? where [target_id] is null',
            $query->limit(null)->getGrammar()->compileUpdate($query, ['note' => 'x'])
        );
    }

    public function test_repeated_select_columns_are_compiled_once()
    {
        $sql = $this->query()
            ->select(['action_logs.id', 'action_logs.note', 'action_logs.note', 'note'])
            ->orderBy('note')
            ->toSql();

        $this->assertSame('select [action_logs].[id], [action_logs].[note], [note] from [action_logs] order by [note] asc', $sql);
    }

    public function test_repeated_order_columns_are_compiled_once()
    {
        $sql = $this->query()
            ->orderBy('created_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'asc')
            ->toSql();

        $this->assertSame('select * from [action_logs] order by [created_at] desc, [id] asc', $sql);
    }

    public function test_first_direction_wins_for_a_repeated_column()
    {
        $sql = $this->query()
            ->orderBy('created_at', 'desc')
            ->orderBy('created_at', 'asc')
            ->toSql();

        $this->assertSame('select * from [action_logs] order by [created_at] desc', $sql);
    }

    public function test_raw_orders_and_their_bindings_are_kept()
    {
        $query = $this->query()
            ->orderBy('created_at', 'desc')
            ->orderByRaw('CASE WHEN [item_id] = ? THEN 0 ELSE 1 END', [5])
            ->orderBy('created_at', 'desc');

        $this->assertSame(
            'select * from [action_logs] order by [created_at] desc, CASE WHEN [item_id] = ? THEN 0 ELSE 1 END',
            $query->toSql()
        );
        $this->assertSame([5], $query->getBindings());
    }
}
