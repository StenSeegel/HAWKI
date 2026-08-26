<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Seeds the settings for the realtime chat voice input / live transcription
 * provider selection. 'onprem' routes microphone audio through the
 * realtime-bridge sidecar to the university's OpenAI-compatible realtime
 * STT endpoint (currently vLLM/Voxtral behind the LiteLLM gateway) —
 * word-level streaming, no audio leaves the premises. 'openai' streams to
 * the OpenAI Realtime API.
 *
 * Merges three earlier migrations from the feature branch (seed 'local' →
 * retire Speaches realtime → rename 'voxtral' to the provider-agnostic
 * 'onprem'), so environments that never ran them start clean; the value
 * normalization below keeps environments that did consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Environments that ran the pre-merge migrations may hold retired
        // provider values ('local' = Speaches realtime, 'voxtral' = the
        // pre-rename identifier) or the old voxtral_* setting keys.
        DB::table('transcription_settings')
            ->where('key', 'chat_realtime_provider')
            ->whereIn('value', ['local', 'voxtral'])
            ->update(['value' => 'onprem']);

        DB::table('transcription_settings')
            ->where('key', 'voxtral_api_provider')
            ->update(['key' => 'onprem_api_provider']);

        DB::table('transcription_settings')
            ->where('key', 'voxtral_realtime_model')
            ->update(['key' => 'onprem_realtime_model']);

        $defaults = [
            [
                'key' => 'chat_realtime_provider',
                'value' => 'onprem',
                'description' => 'Realtime provider for chat voice input (onprem = on-prem realtime bridge, openai = OpenAI Realtime API)',
            ],
            [
                'key' => 'onprem_api_provider',
                'value' => 'ki-at-jlu',
                'description' => 'API provider (unique_name) whose base URL and key reach the gateway serving the on-prem realtime STT model',
            ],
            [
                'key' => 'onprem_realtime_model',
                'value' => 'voxtral-mini-realtime',
                'description' => 'Model name of the on-prem realtime STT model on the gateway',
            ],
        ];

        foreach ($defaults as $setting) {
            $exists = DB::table('transcription_settings')
                ->where('key', $setting['key'])
                ->exists();

            if (! $exists) {
                DB::table('transcription_settings')->insert([
                    'key' => $setting['key'],
                    'value' => $setting['value'],
                    'type' => 'string',
                    'description' => $setting['description'],
                    'is_private' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('transcription_settings')
            ->whereIn('key', ['chat_realtime_provider', 'onprem_api_provider', 'onprem_realtime_model'])
            ->delete();
    }
};
