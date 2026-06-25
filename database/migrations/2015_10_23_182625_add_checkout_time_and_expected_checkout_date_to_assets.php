<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddCheckoutTimeAndExpectedCheckoutDateToAssets extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('assets', function (Blueprint $table) {
            //
            $answer = $table->dateTime('last_checkout')->nullable();
            $table->date('expected_checkin')->nullable();
        });
        $prefix = DB::getTablePrefix();

        DB::statement('UPDATE '.$prefix.'assets SET last_checkout=(SELECT MAX(created_at) FROM '.$prefix.'asset_logs WHERE '.$prefix.'asset_logs.id='.$prefix."assets.id AND action_type='checkout') WHERE assigned_to IS NOT NULL");

        // "single newest row" is expressed as LIMIT 1 in MySQL/SQLite but TOP 1 in SQL Server.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('UPDATE '.$prefix.'assets SET expected_checkin=(SELECT TOP 1 expected_checkin FROM '.$prefix.'asset_logs WHERE '.$prefix.'asset_logs.id='.$prefix."assets.id AND action_type='checkout' ORDER BY id DESC) WHERE assigned_to IS NOT NULL");
        } else {
            DB::statement('UPDATE '.$prefix.'assets SET expected_checkin=(SELECT expected_checkin FROM '.$prefix.'asset_logs WHERE '.$prefix.'asset_logs.id='.$prefix."assets.id AND action_type='checkout' ORDER BY id DESC limit 1) WHERE assigned_to IS NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('assets', function (Blueprint $table) {
            //
            $table->dropColumn('last_checkout');
            $table->dropColumn('expected_checkin');
        });
    }
}
