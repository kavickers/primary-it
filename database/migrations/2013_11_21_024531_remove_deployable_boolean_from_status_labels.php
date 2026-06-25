<?php

use Illuminate\Database\Migrations\Migration;

class RemoveDeployableBooleanFromStatusLabels extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('status_labels', function ($table) {
            $table->dropColumn('deployable');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('status_labels', function ($table) {
            // A default is required so SQL Server can add this NOT NULL column to
            // a non-empty table (MySQL implicitly defaults it; SQL Server does not).
            $table->boolean('deployable')->default(1);
        });
    }
}
