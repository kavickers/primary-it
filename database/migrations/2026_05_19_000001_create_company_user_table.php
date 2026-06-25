<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        // Seed pivot from existing users.company_id values.
        // insertOrIgnore() is not supported by the SQL Server driver, so we
        // explicitly skip pairs that already exist — this is driver-agnostic
        // and preserves the original "ignore duplicates" intent.
        DB::table('users')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->each(function ($user) {
                $alreadyExists = DB::table('company_user')
                    ->where('company_id', $user->company_id)
                    ->where('user_id', $user->id)
                    ->exists();

                if ($alreadyExists) {
                    return;
                }

                DB::table('company_user')->insert([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
