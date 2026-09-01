<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove duplicate entries, keeping only the one with the highest ID for each user_id
        // (which typically represents the most recent entry)
        DB::statement('
            DELETE FROM private_user_data 
            WHERE id NOT IN (
                SELECT MAX(id) 
                FROM private_user_data 
                GROUP BY user_id
            )
        ');

        // Add unique constraint
        Schema::table('private_user_data', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('private_user_data', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
