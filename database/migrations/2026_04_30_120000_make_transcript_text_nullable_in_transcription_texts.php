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
        Schema::table('transcription_texts', function (Blueprint $table) {
            $table->longText('transcript_text')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_texts', function (Blueprint $table) {
            $table->longText('transcript_text')->nullable(false)->change();
        });
    }
};
