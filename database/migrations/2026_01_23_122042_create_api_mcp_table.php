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
        Schema::create('api_mcp', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Unique server identifier (e.g., jlu-mcp, tavily)');
            $table->string('label')->comment('Human-readable label for the server');
            $table->enum('type', ['http', 'stdio'])->default('http')->comment('Server connection type');
            $table->string('url')->nullable()->comment('URL for HTTP-based MCP servers');
            $table->string('command')->nullable()->comment('Command for STDIO-based MCP servers');
            $table->json('args')->nullable()->comment('Arguments for STDIO-based MCP servers');
            $table->json('headers')->nullable()->comment('HTTP headers for authentication');
            $table->boolean('is_active')->default(true)->comment('Whether this server is active');
            $table->integer('display_order')->default(0)->comment('Display order in UI');
            $table->text('description')->nullable()->comment('Server description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_mcp');
    }
};
