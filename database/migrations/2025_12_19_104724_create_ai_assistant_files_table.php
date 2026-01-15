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
        Schema::create('ai_assistant_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_id')->constrained('ai_assistants')->onDelete('cascade');
            $table->foreignId('attachment_id')->constrained('attachments')->onDelete('cascade');
            $table->string('purpose')->default('knowledge'); // knowledge, context, reference
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
            
            $table->unique(['assistant_id', 'attachment_id']);
            $table->index(['assistant_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_files');
    }
};
