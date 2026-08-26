<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the redundant transcript_text column.
     * The full text is derivable from the segments JSON column.
     */
    public function up(): void
    {
        Schema::table('transcription_texts', function (Blueprint $table): void {
            $table->dropColumn('transcript_text');
        });
    }

    /**
     * Restore transcript_text as nullable longText for rollback safety.
     */
    public function down(): void
    {
        Schema::table('transcription_texts', function (Blueprint $table): void {
            $table->longText('transcript_text')->nullable()->after('transcription_id');
        });
    }
};
