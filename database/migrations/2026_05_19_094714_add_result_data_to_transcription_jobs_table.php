<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transcription_jobs', function (Blueprint $table) {
            $table->json('result_data')->nullable()->after('manifest_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_jobs', function (Blueprint $table) {
            $table->dropColumn('result_data');
        });
    }
};
