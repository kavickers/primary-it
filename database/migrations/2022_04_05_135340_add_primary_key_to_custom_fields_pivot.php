<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPrimaryKeyToCustomFieldsPivot extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        // Check if the ID primary key already exists, if not, add it
        Schema::table('custom_field_custom_fieldset', function (Blueprint $table) {
            if (! Schema::hasColumn('custom_field_custom_fieldset', 'id')) {
                $table->bigIncrements('id')->first();
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
        if (! Schema::hasColumn('custom_field_custom_fieldset', 'id')) {
            return;
        }

        // SQL Server will not drop a column that the primary key depends on, and
        // its PK constraint has an auto-generated name, so we look it up and drop
        // it explicitly before dropping the column. MySQL/SQLite handle this in
        // the schema builder, so they keep the plain dropColumn path.
        if (DB::getDriverName() === 'sqlsrv') {
            $table = DB::getTablePrefix().'custom_field_custom_fieldset';

            $pk = DB::selectOne(
                "SELECT name FROM sys.key_constraints WHERE type = 'PK' AND parent_object_id = OBJECT_ID(?)",
                [$table]
            );

            if ($pk) {
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT ['.$pk->name.']');
            }
        }

        Schema::table('custom_field_custom_fieldset', function (Blueprint $table) {
            $table->dropColumn('id');
        });
    }
}
