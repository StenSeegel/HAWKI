<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translate_documents', function (Blueprint $table) {
            $table->id();

            // Job ID from DocumentTranslationService (= the UUID used throughout the flow)
            $table->uuid('job_id')->unique();

            // User relation (nullable: guest/unauthenticated users)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // File metadata
            $table->string('original_name');
            $table->string('output_extension', 10);
            $table->string('target_lang', 10);
            $table->string('source_lang', 10)->nullable();

            // Storage reference — download_id is set once the file is ready
            $table->uuid('download_id')->nullable()->unique();
            $table->string('file_path')->default('');
            $table->unsignedBigInteger('file_size')->nullable();

            // Lifecycle tracking
            $table->enum('status', ['pending', 'translating', 'done', 'error', 'deleted'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translate_documents');
    }
};
