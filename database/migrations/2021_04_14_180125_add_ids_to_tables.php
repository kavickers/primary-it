<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddIdsToTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('migrations', function (Blueprint $table) {
            // Add the id column to the migrations table if it doesn't yet have one
            if (! Schema::hasColumn('migrations', 'id')) {
                $table->increments('id');
            }
        });

        Schema::table('password_resets', function (Blueprint $table) {
            // Add the id column to the password_resets table if it doesn't yet have one
            if (! Schema::hasColumn('password_resets', 'id') && $this->notUsingSqlite()) {
                $table->increments('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->dropIdColumn('migrations');
        $this->dropIdColumn('password_resets');
    }

    /**
     * Drop an auto-increment "id" primary-key column in a driver-agnostic way.
     *
     * SQL Server refuses to drop a column the primary key depends on and names
     * its PK constraint automatically, so we look it up and drop it first.
     * MySQL/SQLite handle this in the schema builder.
     */
    private function dropIdColumn(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlsrv') {
            $table = DB::getTablePrefix().$tableName;

            $pk = DB::selectOne(
                "SELECT name FROM sys.key_constraints WHERE type = 'PK' AND parent_object_id = OBJECT_ID(?)",
                [$table]
            );

            if ($pk) {
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT ['.$pk->name.']');
            }
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('id');
        });
    }

    private function notUsingSqlite()
    {
        return Schema::connection($this->getConnection())->getConnection()->getDriverName() !== 'sqlite';
    }
}
