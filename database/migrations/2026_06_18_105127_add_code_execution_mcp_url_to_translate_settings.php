<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existingSetting = DB::table('app_settings')
            ->where('key', 'hawki_code_execution_mcp_url')
            ->first();

        $value = $existingSetting?->value ?? 'https://ki-mcp01.hrz.uni-giessen.de';

        DB::table('translate_settings')->updateOrInsert(
            ['key' => 'code_execution_mcp_url'],
            [
                'value' => $value,
                'type' => 'string',
                'description' => 'URL of the Code Execution MCP Server',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('app_settings')
            ->where('key', 'hawki_code_execution_mcp_url')
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $existingSetting = DB::table('translate_settings')
            ->where('key', 'code_execution_mcp_url')
            ->first();

        $value = $existingSetting?->value ?? 'https://ki-mcp01.hrz.uni-giessen.de';

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'hawki_code_execution_mcp_url'],
            [
                'value' => $value,
                'source' => 'hawki',
                'group' => 'basic',
                'type' => 'string',
                'description' => 'Code Execution MCP Server URL',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('translate_settings')
            ->where('key', 'code_execution_mcp_url')
            ->delete();
    }
};
