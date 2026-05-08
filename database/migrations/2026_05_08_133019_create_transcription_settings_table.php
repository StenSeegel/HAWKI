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
        Schema::create('transcription_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, boolean, integer, json
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(false);
            $table->timestamps();
        });

        DB::table('transcription_settings')->insert([
            [
                'key' => 'provider',
                'value' => 'custom_speaches',
                'type' => 'string',
                'description' => 'Transcription Service Provider',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'base_url',
                'value' => 'http://134.176.150.177/v1',
                'type' => 'string',
                'description' => 'Custom Speaches API Base URL',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'api_key',
                'value' => null,
                'type' => 'string',
                'description' => 'Custom Speaches API Key',
                'is_private' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'model',
                'value' => 'Systran/faster-whisper-large-v3',
                'type' => 'string',
                'description' => 'Transcription Model',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'diarization_model',
                'value' => 'pyannote/speaker-diarization-community-1',
                'type' => 'string',
                'description' => 'Diarization Model',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'min_speakers',
                'value' => '1',
                'type' => 'integer',
                'description' => 'Minimum Speakers for Diarization',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'max_speakers',
                'value' => '5',
                'type' => 'integer',
                'description' => 'Maximum Speakers for Diarization',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_settings');
    }
};
