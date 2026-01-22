<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds use_native_server_tools flag to api_providers table.
     * Controls whether to use native server tools (OpenAI web_search) or configurable MCP tools.
     */
    public function up(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->boolean('use_native_server_tools')
                ->default(true)
                ->after('is_active')
                ->comment('Use native server tools (true) or configurable MCP tools (false)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->dropColumn('use_native_server_tools');
        });
    }
};
