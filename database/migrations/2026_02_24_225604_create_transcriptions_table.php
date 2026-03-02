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
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable(); // Auto-generated title
            $table->string('slug')->unique(); // For URL routing
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('transcript_text'); // The transcribed text
            $table->json('segments')->nullable(); // Detailed segments with timestamps
            $table->json('words')->nullable(); // Word-level timestamps (if available)
            $table->string('language')->nullable(); // Detected language
            $table->string('user_locale', 10)->default('de_DE'); // User's locale for title generation
            $table->integer('duration')->nullable(); // Audio duration in seconds
            $table->string('model_used')->nullable(); // Which AI model was used
            $table->string('provider')->nullable(); // Which provider (OpenAI, etc.)
            $table->string('original_filename')->nullable(); // Original audio filename
            $table->integer('file_size')->nullable(); // File size in bytes
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
