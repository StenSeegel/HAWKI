<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('translate_settings')->insert([
            'key' => 'allowed_models',
            'value' => json_encode([]),
            'type' => 'json',
            'description' => 'Allowed Models for AI Translation',
            'is_private' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translate_settings')->where('key', 'allowed_models')->delete();
    }
};
