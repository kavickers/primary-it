<?php

namespace Tests\Unit;

use App\Services\SqlServer\SqlServerConnection;
use PHPUnit\Framework\TestCase;

class SqlServerConnectionTest extends TestCase
{
    public function test_fractional_seconds_are_trimmed_from_date_times()
    {
        $record = (object) [
            'last_checkout' => '2026-10-01 09:30:00.000',
            'created_at' => '2026-10-01 09:30:00.1234567',
            'purchase_date' => '2026-10-01',
            'last_audit_date' => null,
            'id' => 7,
        ];

        $this->assertEquals((object) [
            'last_checkout' => '2026-10-01 09:30:00',
            'created_at' => '2026-10-01 09:30:00',
            'purchase_date' => '2026-10-01',
            'last_audit_date' => null,
            'id' => 7,
        ], SqlServerConnection::trimFractionalSeconds($record));
    }

    public function test_array_records_are_trimmed_too()
    {
        $this->assertSame(
            ['next_audit_date' => '2027-01-15 13:45:00'],
            SqlServerConnection::trimFractionalSeconds(['next_audit_date' => '2027-01-15 13:45:00.000'])
        );
    }

    public function test_text_that_is_not_exactly_a_date_time_is_untouched()
    {
        $record = (object) [
            'notes' => 'Swapped at 2026-10-01 09:30:00.000 by IT',
            'serial' => '2026-10-01 09:30:00.000X',
            'asset_tag' => '2026-10-01T09:30:00.000',
        ];

        $this->assertEquals(clone $record, SqlServerConnection::trimFractionalSeconds($record));
    }

    public function test_bigint_columns_are_cast_and_char_padding_is_stripped()
    {
        $record = (object) [
            'source_id' => '8683',
            'digit_separator' => '1.234,56   ',
            'notes' => 'kept as is   ',
            'company_id' => null,
        ];

        $this->assertEquals((object) [
            'source_id' => 8683,
            'digit_separator' => '1.234,56',
            'notes' => 'kept as is   ',
            'company_id' => null,
        ], SqlServerConnection::normalizeColumns($record, [
            'source_id' => 'bigint',
            'digit_separator' => 'char',
            'company_id' => 'bigint',
        ]));
    }

    public function test_dates_are_written_without_milliseconds()
    {
        $connection = new SqlServerConnection(fn () => null, 'snipeit', '', ['driver' => 'sqlsrv']);

        $this->assertSame('Y-m-d H:i:s', $connection->getQueryGrammar()->getDateFormat());
    }
}
