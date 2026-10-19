<?php

namespace Tests\Unit;

use App\Services\SqlServer\SqlServerConnection;
use App\Services\SqlServer\SqlServerQueryBuilder;
use PHPUnit\Framework\TestCase;

class SqlServerQueryBuilderTest extends TestCase
{
    private function query(): SqlServerQueryBuilder
    {
        // The PDO resolver is never invoked: toSql() only compiles.
        $connection = new SqlServerConnection(fn () => null, 'snipeit', '', ['driver' => 'sqlsrv']);

        return $connection->query()->from('users');
    }

    public function test_large_integer_lists_are_inlined_instead_of_bound()
    {
        $ids = range(1, 3000);

        $query = $this->query()->whereIn('id', $ids)->whereNotIn('location_id', collect($ids));

        $this->assertSame(
            'select * from [users] where [id] in ('.implode(', ', $ids).') and [location_id] not in ('.implode(', ', $ids).')',
            $query->toSql()
        );
        $this->assertSame([], $query->getBindings());
    }

    public function test_small_integer_lists_stay_bound()
    {
        $query = $this->query()->whereIn('id', [1, 2, 3]);

        $this->assertSame('select * from [users] where [id] in (?, ?, ?)', $query->toSql());
        $this->assertSame([1, 2, 3], $query->getBindings());
    }

    public function test_large_string_lists_stay_bound()
    {
        $tags = array_map('strval', range(1, SqlServerQueryBuilder::INLINE_INTEGER_LIST_THRESHOLD));

        $query = $this->query()->whereIn('asset_tag', $tags);

        $this->assertSame($tags, $query->getBindings());
    }

    public function test_subquery_values_are_untouched()
    {
        $query = $this->query()->whereIn('id', fn ($sub) => $sub->select('user_id')->from('company_user')->where('company_id', 7));

        $this->assertSame(
            'select * from [users] where [id] in (select [user_id] from [company_user] where [company_id] = ?)',
            $query->toSql()
        );
        $this->assertSame([7], $query->getBindings());
    }
}
