<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('translate_settings')->insert([
            [
                'key' => 'show_beta_message',
                'value' => '0',
                'type' => 'boolean',
                'is_private' => false,
                'description' => 'Show Beta Message',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'beta_message_text',
                'value' => '',
                'type' => 'string',
                'is_private' => false,
                'description' => 'Beta Message Text',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('translate_settings')
            ->whereIn('key', ['show_beta_message', 'beta_message_text'])
            ->delete();
    }
};
