<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL Server only. Custom fields store their values in TEXT columns on the
 * assets table, except the legacy MAC Address field, which
 * 2015_09_22_003413_migrate_mac_address created from a string(255) column.
 * An encrypted value doesn't fit in 255 characters: non-strict MySQL silently
 * truncates it, SQL Server rejects the write, so once that field was set to
 * encrypted no asset carrying a MAC address could be saved. Widen any such
 * column to match the other custom fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        $customFieldColumns = DB::table('custom_fields')->pluck('db_column')->filter()->all();

        $limitedColumns = collect(DB::select(
            "select column_name from information_schema.columns
            where table_name = ? and data_type in ('nvarchar', 'varchar') and character_maximum_length > 0",
            [DB::getTablePrefix().'assets']
        ))->pluck('column_name')->intersect($customFieldColumns);

        foreach ($limitedColumns as $column) {
            Schema::table('assets', function (Blueprint $table) use ($column) {
                $table->text($column)->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        // Narrowing the column back could truncate encrypted values.
    }
};
