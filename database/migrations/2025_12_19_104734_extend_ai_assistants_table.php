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
        Schema::table('ai_assistants', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('description');
            $table->json('conversation_starters')->nullable()->after('tools');
            $table->unsignedBigInteger('usage_count')->default(0)->after('tools');
            $table->string('category')->nullable()->after('status');
            $table->text('full_system_prompt')->nullable()->after('prompt');
            $table->timestamp('published_at')->nullable();
            
            $table->index('category');
            $table->index('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_assistants', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropIndex(['published_at']);
            $table->dropColumn([
                'avatar_path',
                'conversation_starters',
                'usage_count',
                'category',
                'full_system_prompt',
                'published_at',
            ]);
        });
    }
};
