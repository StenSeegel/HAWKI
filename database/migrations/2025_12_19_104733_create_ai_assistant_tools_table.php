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
        Schema::create('ai_assistant_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_id')->constrained('ai_assistants')->onDelete('cascade');
            $table->string('tool_type'); // web_search, file_upload, code_interpreter, etc.
            $table->boolean('is_enabled')->default(true);
            $table->json('configuration')->nullable(); // Tool-specific settings
            $table->timestamps();
            
            $table->unique(['assistant_id', 'tool_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_tools');
    }
};
