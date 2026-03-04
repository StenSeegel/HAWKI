<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translate_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, boolean, integer, json
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(false);
            $table->timestamps();
        });

        DB::table('translate_settings')->insert([
            [
                'key' => 'deepl_api_key',
                'value' => null,
                'type' => 'string',
                'description' => 'DeepL API Key',
                'is_private' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'filter_glossary',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Filter Glossary Entries',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'allowed_models',
                'value' => json_encode([]),
                'type' => 'json',
                'description' => 'Allowed Models for AI Translation',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_model',
                'value' => null,
                'type' => 'string',
                'description' => 'Default Translation Model',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'show_beta_message',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Show Beta Message',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'beta_message_text',
                'value' => '',
                'type' => 'string',
                'description' => 'Beta Message Text',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('translate_settings');
    }
};
