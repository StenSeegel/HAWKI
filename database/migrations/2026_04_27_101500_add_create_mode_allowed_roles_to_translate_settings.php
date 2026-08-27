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
            ['key' => 'create_mode_allowed_roles'],
            [
                'value' => '[]',
                'type' => 'string',
                'description' => 'Allowed Roles for Create Mode. Empty list allows all.',
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
        DB::table('translate_settings')
            ->where('key', 'create_mode_allowed_roles')
            ->delete();
    }
};
