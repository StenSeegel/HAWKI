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
        DB::table('translate_settings')->updateOrInsert(
            ['key' => 'show_debug_infos'],
            [
                'value' => 'false',
                'type' => 'boolean',
                'description' => 'Enable detailed logging for translation requests',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('translate_settings')->where('key', 'show_debug_infos')->delete();
    }
};
