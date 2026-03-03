<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('translate_settings')->insert([
            'key' => 'default_model',
            'value' => null,
            'type' => 'string',
            'description' => 'Default Translation Model',
            'is_private' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translate_settings')->where('key', 'default_model')->delete();
    }
};
