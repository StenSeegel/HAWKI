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
        $settings = [
            [
                'key' => 'translate_model',
                'description' => 'Default model for the "Text Translate" feature (translateModel).',
            ],
            [
                'key' => 'rephrase_model',
                'description' => 'Default model for the "Text Rephrase" feature (rephraseModel).',
            ],
            [
                'key' => 'alternative_sentence_model',
                'description' => 'Default model for the "Alternative Sentence" feature (alternativeSentenceModel).',
            ],
            [
                'key' => 'replace_word_model',
                'description' => 'Default model for the "Replace Word / Synonyms" feature (replaceWordModel).',
            ],
            [
                'key' => 'correction_model',
                'description' => 'Default model for adjusting sentence structure after word replacement (correctionModel).',
            ],
            [
                'key' => 'detection_model',
                'description' => 'Model used for automatic language detection.',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('translate_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => null,
                    'type' => 'string',
                    'description' => $setting['description'],
                    'is_private' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Cleanup redundant or old implementation keys
        DB::table('translate_settings')
            ->whereIn('key', ['default_model', 'alternatives_model', 'synonyms_model'])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('translate_settings')
            ->whereIn('key', [
                'translate_model',
                'rephrase_model',
                'alternative_sentence_model',
                'replace_word_model',
                'correction_model',
                'detection_model',
            ])
            ->delete();

        // Restore default_model if it was removed
        DB::table('translate_settings')->updateOrInsert(
            ['key' => 'default_model'],
            [
                'value' => null,
                'type' => 'string',
                'description' => 'Default Translation Model',
                'is_private' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
